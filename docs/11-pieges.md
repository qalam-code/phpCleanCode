# 11. Les sept pièges

Aucun n'est théorique. Chacun a été rencontré pendant le refactoring d'une API
de paiement en production, et c'est ce qui explique pourquoi le socle est écrit
comme il l'est.

Ils ont un point commun, et c'est ce qui les rend coûteux : **ils passent les
tests.**

---

## Piège 1 — Le constructeur d'exception privé

### Le symptôme

```
PHP Fatal error: Access level to App\Paiement\Domain\ErreurMetier::__construct()
must be public (as in class Exception)
```

Sept endpoints, tous hors service. Pas un seul appel servi.

### La cause

```php
final class ErreurMetier extends Exception
{
    private function __construct($type, $detail)   // ← fatal sous PHP 7.0
    {
        parent::__construct($detail);
        $this->type = $type;
    }
}
```

L'intention était bonne : forcer le passage par les fabriques nommées. PHP
l'interdit — une méthode qui en redéfinit une autre ne peut pas réduire sa
visibilité, et `Exception::__construct()` est publique.

**Le refus est à la déclaration, pas à l'usage.** La classe ne se charge pas ;
tout ce qui l'importe tombe avec elle. Et comme `ErreurMetier` est importée par
les sept endpoints, l'API entière était morte.

### Pourquoi rien ne l'avait vu

| Outil | Pourquoi il ne voit rien |
|---|---|
| `php -l` | ne vérifie que la **syntaxe** ; ne déclare pas les classes |
| 251 contrôles verts | tournaient sous **PHP 8.4**, qui a relâché la règle pour les constructeurs |
| revue de code | la construction est parfaitement légale sous PHP 8 |

C'est l'asymétrie qui tue : poste de développement en PHP 8, production en
PHP 7.0.

### Ce que le socle en fait

**1. Le constructeur est public, avec le commentaire qui explique pourquoi** —
sinon quelqu'un le « corrigera » dans six mois.

**2. `outils/compat.php`** déclare toutes les classes et laisse PHP se plaindre.
C'est ce que `php -l` ne peut pas faire.

**3. `outils/verification.php`** applique la règle stricte **par réflexion**,
quelle que soit la version qui l'exécute :

```php
$constructeur = (new ReflectionClass(ErreurMetier::class))->getConstructor();
$v->vrai($constructeur !== null && $constructeur->isPublic(), '…');
```

C'est le seul contrôle qui protège depuis un poste plus récent que la
production.

### La leçon générale

> **Une suite de tests verte sous une version de PHP ne prouve rien pour une
> autre.** Lancez `lint` avec le binaire de production.

---

## Piège 2 — La connexion ouverte trop tôt

### Le symptôme

Base indisponible. Une requête **sans en-tête `Authorization`** reçoit :

```json
{"statut":"erreur","message":"erreur interne"}
```

au lieu de :

```json
{"statut":"erreur","message":"token introuvable"}
```

Le client cherche une panne chez lui ; il lui manquait juste un jeton.

### La cause

La racine de composition câblait l'authentificateur dans tous les cas d'usage.
Le construire exigeait un dépôt, donc la connexion :

```php
public function authentificateur()
{
    return new Authentificateur(
        $this->requete(),
        $this->jetons(),
        new DepotUtilisateurPdo($this->connexion()->pdo())   // ← ouvre la socket
    );
}
```

L'endpoint échouait **avant** d'avoir regardé la requête.

### Pourquoi rien ne l'avait vu

Les tests travaillaient avec des doubles : aucune connexion, donc aucune
ouverture prématurée. Le défaut n'existe que lorsque la base est injoignable —
c'est-à-dire jamais, en test.

Il a été trouvé par une fumigation : lancer l'API avec un DSN volontairement
faux, et regarder ce qu'elle répond.

### Ce que le socle en fait

**Deux niveaux de paresse superposés** :

| Classe | Ce qu'elle retarde |
|---|---|
| `FabriqueConnexion` | la socket, jusqu'au premier `pdo()` |
| `AuthentificationDifferee` | la construction de l'authentificateur, jusqu'au premier `identifier()` |

Et la vérification, de bout en bout :

```
JWT_SECRET=x DB_HOTE=255.255.255.255 php -S 127.0.0.1:8000 -t public public/index.php
curl "http://127.0.0.1:8000/facture?numero=F-1"
# 400 {"statut":"erreur","message":"token introuvable"}
```

### La leçon générale

> **Câbler n'est pas travailler.** La racine de composition assemble des objets ;
> elle n'ouvre rien, n'appelle rien, ne lit rien.

---

## Piège 3 — Le présentateur unique

### Le symptôme

Base indisponible. **Tous** les endpoints répondent :

```json
{"statut":"erreur","message":"identifiants invalides"}
```

Les consommateurs ont vérifié leurs identifiants, les ont régénérés, ont ouvert
un ticket. Le problème était ailleurs.

### La cause

Le point d'entrée attrapait tout et rendait l'erreur avec **un présentateur de
secours unique** — celui de la connexion, qui traduit `IDENTIFIANTS_INVALIDES`
en 403.

```php
try {
    // câblage + exécution
} catch (Throwable $e) {
    return $this->presentateurConnexion->traduire($e);   // ← toujours le même
}
```

### Ce que le socle en fait

**Chaque route porte son propre présentateur** :

```php
$routeur->ajouter(
    'facture',
    function () { /* le contrôleur */ },
    function () { return new PresentateurFacture(); },   // ← celui de CETTE route
    ['GET']
);
```

`Aiguillage` construit le présentateur **avant** le `try`, puis exécute dedans :
une panne de câblage est rendue au format de l'endpoint appelé.

Le présentateur de secours, lui, ne sert qu'aux erreurs qui ne concernent aucune
route — chemin inconnu, méthode refusée — et n'a **aucune dépendance**, donc rien
qui puisse échouer à son tour.

Un contrôle d'équivalence le vérifie : une route dont le câblage lève est rendue
en 500 par son propre présentateur, et le message technique ne fuit pas.

### La leçon générale

> **Un message d'erreur est une information de diagnostic.** S'il est faux, il
> envoie tout le monde chercher au mauvais endroit.

---

## Piège 4 — Le double qui ment

### Le symptôme

Aucun. C'est le pire des quatre premiers.

Un endpoint avait un chemin d'erreur `403 {"message": "erreur sauvegarde action"}`,
testé, vert, documenté. **Il n'avait jamais pu se produire en production.**

### La cause

Le double de journal levait une exception en cas d'échec :

```php
class JournalFactice
{
    public function enregistrer($acteur, $action)
    {
        if (!$this->reussit) {
            throw new RuntimeException('echec');   // ← le vrai dépôt ne lève JAMAIS
        }
    }
}
```

Le dépôt réel, lui, attrapait tout et retournait sans rien dire. Le cas d'usage
avait donc un `catch` qui ne pouvait jamais se déclencher, et un message d'erreur
qui ne pouvait jamais sortir.

Une branche entière de code : testée, verte, morte.

### Ce que le socle en fait

**Le contrat tranche, pas l'implémentation.** `JournalInterface::enregistrer()`
rend un `bool` et **ne lève jamais** — c'est écrit dans l'interface, appliqué par
`JournalPdo`, et respecté par `JournalFactice`.

L'avertissement est en tête du double, là où quelqu'un le lira :

> *Un double qui se comporte autrement que l'objet réel rend les tests verts et
> la production fausse. Avant d'écrire un double, relisez l'implémentation
> réelle.*

Le contrôle correspondant vérifie le comportement du double lui-même :

```php
$journal->reussit = false;
$v->egal(false, $journal->enregistrer(1, 'x'), 'le journal rend false et NE LÈVE PAS');
```

### La leçon générale

> **Un double est une hypothèse sur le réel.** Une hypothèse fausse produit des
> tests verts sur du code mort.

---

## Piège 5 — Le message technique qui fuit

### Le symptôme

```json
{"statut":"erreur","message":"SQLSTATE[HY000] [2002] Connection refused"}
```

Le consommateur apprend le moteur de base, l'hôte, le port — et, selon
l'erreur, l'utilisateur SQL.

Variante plus discrète, rencontrée aussi :

```json
{"statut":"erreur","statut":"Impossible d'obtenir le verrou external_api_token_lock"}
```

Aucune donnée sensible, mais le nom interne d'un verrou : de quoi cartographier
l'implémentation, et de quoi laisser croire à une erreur de la part de l'appelant.

### La cause

Un `catch` qui relaie `$e->getMessage()` au lieu de traduire.

### Ce que le socle en fait

**La règle est inscrite dans `PresentateurAbstrait`** :

> *Un présentateur ne laisse jamais passer le message d'une exception technique.
> Traduisez, ne relayez pas.*

`PresentateurCommun` traduit `ECHEC_ECRITURE` et `ECHEC_JOURNALISATION` en
`panne()` — le détail reste dans `getMessage()`, pour le journal.

**Une seule exception, assumée** : `PARAMETRE_MANQUANT` laisse passer son détail,
parce qu'il nomme les paramètres absents. C'est une information utile à
l'appelant, qui ne révèle rien du système — et elle est composée par une fabrique
nommée, pas par une couche technique.

Deux contrôles d'équivalence vérifient qu'aucun `SQLSTATE` n'apparaît dans un
corps de réponse.

### La leçon générale

> **Le consommateur ne doit pas connaître les détails d'implémentation.** Ni le
> moteur, ni l'hôte, ni le nom d'un verrou.

---

## Piège 6 — Les tables non préfixées

### Le symptôme

Une copie de développement qui lit — et écrit — en production. Sans erreur, sans
avertissement.

### La cause

Sur 61 requêtes, 42 nommaient leur base (`flexeau_db.factures`) et **19 ne la
nommaient pas** (`factures`). Ces 19 s'adressaient à la base par défaut du compte
MySQL, qui n'a rien à voir avec ce que le code croit ouvrir.

Dupliquer le projet et changer le DSN ne suffit donc pas : les 42 requêtes
qualifiées partent vers la base de développement, les 19 autres vers la
production. Le pire des deux mondes.

### Ce que le socle en fait

**Un `USE` explicite, juste après la connexion** :

```php
$pdo = new PDO($dsn, $utilisateur, $motDePasse, $options);
if ($this->base !== null) {
    $pdo->exec('USE `' . $this->base . '`');
}
```

Les requêtes non qualifiées suivent alors la même base que les autres. Ce n'est
pas une excuse pour ne pas qualifier — c'est le filet pour le jour où l'on
oublie.

> **⚠** Le filet ne couvre que les connexions ouvertes par `FabriqueConnexion`.
> Un script d'analyse qui ouvre la sienne doit émettre le même `USE`.

### La leçon générale

> **Ce qui n'est pas explicite est décidé par la configuration du serveur.** Et
> la configuration du serveur n'est pas dans votre dépôt.

---

## Piège 7 — L'ordre des clés JSON

### Le symptôme

Aucun de votre côté. Un consommateur se casse, et vous n'avez « rien changé ».

### La cause

```php
// avant
return ['statut' => 'succes', 'token' => $jeton, 'expire_dans' => 3600];
// après un « rangement »
return ['token' => $jeton, 'statut' => 'succes', 'expire_dans' => 3600];
```

`json_encode` respecte l'ordre du tableau. Un client écrit à la main — parseur
maison, expression régulière, comparaison de chaîne complète — s'en aperçoit.

**On ne peut pas savoir qui fait ça.** Sur une API consommée par plusieurs
services tiers dont certains ont dix ans, l'hypothèse prudente est : quelqu'un le
fait.

### Ce que le socle en fait

**`ReponseHttp` documente la règle**, et `Verificateur::reponseEgale()` la
surveille :

```php
$v->egal(array_keys($corpsAttendu), array_keys($obtenue->corps()),
    $libelle . ' -- cles et ordre');
```

Trois contrôles sont produits par appel : le code, les clés **et leur ordre**, le
contenu. Une réorganisation de code qui change l'ordre fait rougir la suite.

### La leçon générale

> **Le contrat d'une API, ce n'est pas seulement ce qu'elle rend : c'est aussi
> comment.** Codes HTTP, noms de clés, ordre des clés, et jusqu'aux fautes de
> frappe des messages.

---

## Ce qu'ils ont en commun

| Piège | Passe les tests parce que… |
|---|---|
| 1. Constructeur privé | les tests tournent sous une autre version de PHP |
| 2. Connexion trop tôt | les tests n'ont pas de base à faire tomber |
| 3. Présentateur unique | les tests ne font pas échouer le câblage |
| 4. Double qui ment | le double est l'hypothèse, et elle est fausse |
| 5. Message qui fuit | personne ne vérifie ce qui **ne doit pas** apparaître |
| 6. Tables non préfixées | les tests tournent sur une seule base |
| 7. Ordre des clés | personne ne compare l'ordre |

Aucun ne se voit en lisant le code, et aucun ne se voit en lançant une suite
ordinaire. Ils se voient quand on **vérifie l'absence** — pas d'exception qui
fuit, pas de connexion ouverte, pas de `SQLSTATE` dans la réponse, pas de clé
déplacée.

C'est ce que font les 36 contrôles de `verification.php` et les 43 du squelette.
C'est ce que font les 55 contrôles de `verification.php` (57 avec PDO SQLite) et les 53 du squelette.
