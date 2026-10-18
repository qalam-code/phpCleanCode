# 3. La couche Infrastructure

`src/Infrastructure/` — **six fichiers**. Tout ce qui parle au monde extérieur :
base de données, cryptographie, horloge.

C'est la seule couche qui a le droit de connaître PDO. Elle dépend du domaine
(elle implémente ses contrats) ; le domaine ne la connaît pas.

---

## 3.1 `FabriqueConnexion`

**Signature**

```php
final class FabriqueConnexion
{
    public function __construct(
        string $dsn,
        string $utilisateur,
        string $motDePasse,
        ?string $base = null,
        array $options = []
    );
    public function pdo(): PDO;          // @throws RuntimeException
    public function estOuverte(): bool;
}
```

**Rôle** — Une connexion PDO unique, ouverte au dernier moment.

**Pourquoi paresseuse** — Tant que personne n'appelle `pdo()`, aucune socket
n'est ouverte. La racine de composition peut donc câbler toute l'application —
dépôts, journal, authentification — **sans toucher la base**. Un endpoint qui
refuse une requête malformée répond sans avoir jamais consulté le serveur.

C'est ce qui permet à une requête sans jeton de recevoir « token introuvable »
même quand la base est morte, au lieu d'une panne générique.

**Le paramètre `$base` est un filet de sécurité.** S'il est fourni, un
`USE \`base\`` explicite est émis juste après la connexion. La connexion n'est
conservée qu'après son succès : un échec ne peut pas laisser réutiliser la base
par défaut du compte.

> **⚠** Dès qu'une seule requête du projet nomme une table sans préfixer sa
> base, c'est la base par défaut du compte MySQL qui décide — et une copie de
> développement se met à lire **et écrire** en production sans qu'aucun test ne
> le signale. Voir [`11-pieges.md`](11-pieges.md), piège n° 6.

**Les options par défaut**, écrasables :

```php
PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION   // échouer bruyamment
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC         // pas de doublons numériques
PDO::ATTR_EMULATE_PREPARES   => false                    // vraies requêtes préparées
```

`EMULATE_PREPARES => false` compte : avec l'émulation, c'est PDO qui interpole
les valeurs dans la chaîne SQL, et la protection contre l'injection dépend alors
du bon réglage de l'encodage.

**L'exception est retraduite** :

```php
catch (PDOException $e) {
    throw new RuntimeException('connexion a la base impossible', 0, $e);
}
```

Le message de PDO contient l'hôte, le port et parfois l'utilisateur. Il ne doit
jamais remonter au consommateur. L'original reste accessible par
`getPrevious()`, pour le journal.

**Utilisation**

```php
$connexion = new FabriqueConnexion(
    'mysql:host=127.0.0.1;dbname=exemple;charset=utf8mb4',
    'exemple', $motDePasse,
    'exemple'          // ← le USE explicite
);
// rien n'est ouvert à ce stade
$connexion->estOuverte();   // false
```

---

## 3.2 `DepotPdo`

**Signature**

```php
abstract class DepotPdo
{
    public function __construct(FabriqueConnexion $connexion);

    final protected function pdo(): PDO;
    final protected function executer(string $sql, array $parametres = []): PDOStatement;
    final protected function uneLigne(string $sql, array $parametres = []): ?array;
    final protected function lignes(string $sql, array $parametres = []): array;
    final protected function scalaire(string $sql, array $parametres = []);   // mixed|null
}
```

**Rôle** — Socle commun des dépôts SQL.

**Pourquoi pas de CRUD** — Une classe mère qui offrirait `find()`, `save()` et
`delete()` imposerait une table par entité et une clé primaire nommée `id`, ce
qui est faux dès la première base héritée. Elle fournit ce qui se répète
vraiment — préparer, exécuter, ramener une ligne ou un scalaire — et rien de
plus.

**La connexion arrive par la fabrique, jamais par un PDO déjà ouvert.** C'est ce
qui garde la paresse jusqu'à la première requête réellement exécutée.

**Tous les helpers sont `final protected`** : un dépôt les utilise, personne ne
les redéfinit, et aucun code extérieur n'accède au PDO.

> **⚠ Toujours passer par `$parametres`.** Concaténer une valeur dans `$sql`,
> même « sûre » parce qu'elle vient d'un entier, est la porte d'entrée des
> injections SQL. Les noms de tables et de colonnes, eux, ne peuvent pas être
> liés : s'ils sont dynamiques, validez-les contre une liste blanche.

**`uneLigne()` et `scalaire()` rendent `null`, pas `false`.** PDO renvoie
`false` quand il n'y a pas de résultat, ce qui se confond avec une valeur `0` ou
une chaîne vide légitime. La normalisation en `null` supprime cette ambiguïté.

**Utilisation**

```php
final class DepotFacturePdo extends DepotPdo implements DepotFactureInterface
{
    public function trouverParNumero(string $numero): ?Facture
    {
        $ligne = $this->uneLigne(
            'SELECT numero, montant_centimes, statut, date_emission'
            . ' FROM factures WHERE numero = :numero LIMIT 1',
            [':numero' => $numero]
        );
        return $ligne === null ? null : $this->hydrater($ligne);
    }
}
```

**La méthode `hydrater()` est la frontière.** Au-dessus d'elle on parle en
`Facture` ; en dessous, en lignes de table. Elle convertit aussi les types,
puisque PDO rend des chaînes même pour les colonnes numériques.

---

## 3.3 `JetonJwt`

**Signature**

```php
final class JetonJwt implements JetonInterface
{
    public function __construct(string $secret);   // @throws InvalidArgumentException
    public function emettre(array $charge, int $dureeSecondes): string;
    public function verifier(string $jeton): array;
}
```

**Rôle** — JWT signé en HS256, sans dépendance externe.

**Format** — Trois segments en base64url séparés par des points : entête, charge,
signature. La signature couvre `entete.charge` ; changer un octet de l'un ou de
l'autre l'invalide.

**Trois pièges, tous évités ici** :

**1. L'algorithme annoncé par le jeton n'est jamais consulté.** Une bibliothèque
qui lit `alg` dans l'entête accepte un jeton disant `"alg":"none"` —
c'est-à-dire non signé. HS256 est imposé, en dur.

**2. La comparaison de signature passe par `hash_equals()`.** Un `===` rend son
verdict d'autant plus vite que les chaînes diffèrent tôt, ce qui suffit à
reconstituer une signature valide octet par octet.

**3. `exp` est obligatoire.** Un jeton sans date d'expiration ne périme jamais ;
il est refusé (`JETON_MALFORME`) plutôt que de se voir accorder une validité
perpétuelle.

> **⚠ La charge n'est pas un secret.** Elle est *encodée*, pas chiffrée :
> n'importe qui la lit avec un décodeur base64. N'y mettez ni mot de passe, ni
> donnée personnelle. Mettez-y le strict minimum pour retrouver l'acteur —
> typiquement `{"sub": 42}`.

**Un secret vide fait échouer la construction.** Un secret vide signe tout de
même, silencieusement, et tous les jetons deviennent forgeables. Mieux vaut
échouer au démarrage.

**Utilisation**

```php
$jwt   = new JetonJwt(getenv('JWT_SECRET'));
$jeton = $jwt->emettre(['sub' => $identite->id()], 3600);
// iat et exp sont ajoutés automatiquement

$charge = $jwt->verifier($jeton);   // ['sub' => 42, 'iat' => …, 'exp' => …]
```

Sept contrôles de `outils/verification.php` portent sur cette classe, dont
quatre sur ce qu'elle doit **refuser**.

---

## 3.4 `JournalPdo`

**Signature**

```php
final class JournalPdo extends DepotPdo implements JournalInterface
{
    public function __construct(FabriqueConnexion $connexion, string $table = 'journal');
    public function enregistrer(int $acteurId, string $action, string $detail = ''): bool;
}
```

**Rôle** — Journal en base, qui ne fait jamais tomber l'appelant.

**Le `catch (Throwable)` est une promesse, pas une négligence.** Le contrat
(`JournalInterface`) dit que cette méthode ne lève pas ; le `catch` est la
traduction d'un échec d'écriture en `false`, pour que l'opération métier déjà
accomplie ne soit pas annulée par sa propre trace.

**Schéma attendu** — La table doit avoir `acteur_id`, `action`, `detail`,
`horodatage` :

Le nom de table est un identifiant SQL, pas une valeur paramétrable : le
constructeur n'accepte que les lettres ASCII, les chiffres et `_`, avec une
lettre ou `_` en premier caractère, et une longueur maximale de 64 caractères.
Les valeurs d'action et de détail restent, elles, liées par paramètres PDO.

```sql
CREATE TABLE journal (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    acteur_id  INT UNSIGNED NOT NULL,
    action     VARCHAR(64)  NOT NULL,
    detail     TEXT         NULL,
    horodatage DATETIME     NOT NULL,
    INDEX (acteur_id, horodatage)
) ENGINE=InnoDB;
```

Si votre schéma diffère, n'adaptez pas la table : écrivez votre propre
implémentation de `JournalInterface`. C'est à cela que sert le contrat.

---

## 3.5 `SurveillanceTimeout`

**Signature**

```php
final class SurveillanceTimeout implements SurveillanceInterface
{
    public function __construct(float $limiteSecondes = 25.0, float $margeSecondes = 5.0);
    public function tempsRestant(): bool;
    public function ecoule(): float;
}
```

**Rôle** — Garde-temps démarré à la construction et basé sur l'horloge
monotone `hrtime()`, qui ne saute pas quand l'heure système est corrigée.

**La marge est le point important.** `tempsRestant()` répond `true` tant que
`ecoule() < limite - marge`. Elle ne dit donc pas « reste-t-il du temps ? » mais
« reste-t-il **au moins une étape** de temps ? ».

> **⚠** Une surveillance qui laisse partir un appel sortant à une seconde de la
> limite se fait couper au milieu. C'est exactement la situation où l'argent est
> débité chez le fournisseur sans que la réponse soit enregistrée chez vous.

**Réglez la marge sur la durée de l'étape la plus longue**, pas sur zéro. Si un
appel au fournisseur prend jusqu'à huit secondes, la marge est de huit secondes.

**Utilisation** — `Fabrique::surveillance()` la câble sur `max_execution_time` :

```php
$limite = (float) ini_get('max_execution_time');
return new SurveillanceTimeout($limite > 0 ? $limite : 30.0, 5.0);
```

---

## 3.6 `AuthentificationDifferee`

**Signature**

```php
final class AuthentificationDifferee implements AuthentificationInterface
{
    public function __construct(callable $construire);   // (): AuthentificationInterface
    public function identifier(): Identite;
}
```

**Rôle** — Enveloppe qui retarde la construction du véritable authentificateur.

**Ne pas la prendre pour une élégance gratuite : elle répare un défaut précis.**

La racine de composition câble l'authentificateur dans chaque cas d'usage. Si le
construire exige un dépôt — donc la base — alors une base injoignable fait
échouer l'endpoint **avant** qu'il ait pu examiner la requête. Une requête sans
jeton reçoit « erreur interne » au lieu de « token introuvable » : le diagnostic
rendu au consommateur devient faux, et l'anomalie ne se voit que le jour où la
base tombe.

Avec cette enveloppe, rien n'est construit tant que `identifier()` n'est pas
appelé — c'est-à-dire tant qu'un cas d'usage n'a pas vraiment besoin de savoir
qui appelle.

**Utilisation** — toujours via `Fabrique::authentificationDifferee()` :

```php
return $this->authentificationDifferee(function () {
    return new Authentificateur(
        $this->requete(),
        $this->jetons(),
        new ResolveurDepot($this->utilisateurs())   // ← touche la base
    );
});
```

**Vérification** — trois contrôles d'auto-test couvrent ce comportement, et la
fumigation HTTP du squelette le montre de bout en bout : base injoignable,
réponse `400 token introuvable`.
