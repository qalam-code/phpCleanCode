# 6. La racine de composition

`src/Fabrique.php` — **un fichier**, et probablement le plus important du socle.

---

## 6.1 `Fabrique`

**Signature**

```php
abstract class Fabrique
{
    public function __construct(Requete $requete);

    abstract public function routeur(): Routeur;
    abstract protected function decrireConnexion(): FabriqueConnexion;

    final public function requete(): Requete;
    final public function connexion(): FabriqueConnexion;
    public function surveillance(): SurveillanceInterface;

    final protected function partage(string $cle, callable $construire);          // mixed
    final protected function definirService(string $identifiant, callable $fabrique, bool $partagee = true);
    final protected function resoudreService(string $identifiant);                // mixed
    final protected function authentificationDifferee(callable $construire): AuthentificationInterface;
}
```

**Rôle** — L'endroit où les implémentations concrètes sont assemblées et reliées aux contrats de l'application. Les objets simples de valeur peuvent être construits ailleurs ; les dépendances d'infrastructure restent câblées à cette frontière.

---

## 6.2 Pourquoi concentrer toutes les instanciations

Dans les cas d'usage et le domaine, une classe **reçoit** ses dépendances et ne fabrique pas ses adaptateurs d'infrastructure.
Cette discipline a un coût d'écriture — cette classe grossit — et un bénéfice
qu'aucune autre technique ne donne : tout le reste du code devient testable sans
base, sans réseau et sans serveur, parce qu'il suffit de lui passer autre chose.

**Lisez cette classe comme le plan du système.** Qui dépend de qui y est écrit
noir sur blanc, en un seul fichier. Sur un projet repris, c'est le premier
fichier à ouvrir : il dit quels endpoints existent, quel cas d'usage répond à
chacun, et de quoi ce cas d'usage dépend.

---

## 6.3 Deux règles à ne pas enfreindre

**1. Rien ici n'ouvre de connexion ni n'appelle le réseau.** On assemble des
objets, on ne travaille pas. `FabriqueConnexion` et `AuthentificationDifferee`
existent pour cela.

> **⚠** Le jour où la fabrique ouvre la base pour se construire, un endpoint qui
> devait répondre « paramètre manquant » répond « erreur interne » dès que la
> base est indisponible.

**2. Aucun cas d'usage n'instancie d'infrastructure.** Le jour où un dépôt fait
`new PDO` dans un coin, la racine de composition ment — et le test qui croyait
travailler en mémoire attaque la base de production.

Ce point se vérifie mécaniquement :

```bash
grep -rn "new PDO\|new .*Pdo(" src/ --include="*.php" | grep -v Fabrique
```

---

## 6.4 `partage()` : mémoïsation

```php
private function utilisateurs(): DepotUtilisateurInterface
{
    return $this->partage('utilisateurs', function () {
        return new DepotUtilisateurPdo($this->connexion());
    });
}
```

Deux cas d'usage câblés dans la même requête partagent alors le même dépôt, donc
la même connexion — et non deux.

**La clé est une chaîne libre**, mais elle doit être unique : deux services
partageant la même clé se substituent silencieusement l'un à l'autre. Prenez le
nom de la méthode.

**`array_key_exists` plutôt que `isset`** : un service qui vaudrait légitimement
`null` serait sinon reconstruit à chaque appel.

---

## 6.5 `Conteneur` : résolution explicite

Le conteneur ne devine pas les dépendances par réflexion. La fabrique lui
enregistre des fabriques concrètes, identifiées par une clé, puis demande leur
résolution quand une route est appelée. Par défaut, le service est partagé
pendant la durée de vie de cette fabrique (une requête HTTP). Passez `false`
au troisième argument de `definirService()` pour obtenir une nouvelle instance
à chaque résolution.

```php
$this->definirService(ControleurFacture::class, function () {
    return new ControleurFacture($this->consulterFacture(), new PresentateurFacture());
});
```

Les fabriques peuvent recevoir le conteneur si elles doivent résoudre une
dépendance, mais cela reste réservé à la composition. Un contrôleur ou un cas
d'usage qui reçoit le conteneur pourrait choisir lui-même ses dépendances au
moment de l'exécution : il dépendrait alors d'un *service locator*. À la place,
on lui passe directement les objets dont il a besoin dans son constructeur.

Les identifiants dupliqués, les services inconnus et les dépendances circulaires
sont signalés par des exceptions explicites. Un cycle indique son chemin complet
(par exemple `A -> B -> A`). Dans le squelette, une liaison de route manquante
nomme également la route et la fabrique attendue. Il n'y a ni auto-wiring ni
instanciation par réflexion : chaque lien reste visible dans `Fabrique.php`.

## 6.6 `connexion()` : paresseuse deux fois

```php
final public function connexion(): FabriqueConnexion
{
    if ($this->connexion === null) {
        $this->connexion = $this->decrireConnexion();
    }
    return $this->connexion;
}
```

Deux niveaux de paresse se superposent, et c'est voulu :

1. `decrireConnexion()` n'est appelée qu'au premier `connexion()` — l'objet
   décrivant la connexion n'est même pas construit tant qu'aucun dépôt n'est
   câblé ;
2. `FabriqueConnexion::pdo()` n'ouvre la socket qu'à la première requête SQL
   réellement exécutée.

Résultat : un endpoint qui refuse une requête malformée ne touche jamais la
base.

---

## 6.7 `surveillance()` : réglée sur la configuration du serveur

```php
public function surveillance(): SurveillanceInterface
{
    return $this->partage('surveillance', function () {
        $limite = (float) ini_get('max_execution_time');
        return new SurveillanceTimeout($limite > 0 ? $limite : 30.0, 5.0);
    });
}
```

`max_execution_time` vaut `0` en CLI (pas de limite) : le repli à 30 secondes
évite qu'un script en ligne de commande croie avoir un budget infini alors que
le même code tournera sous Apache avec une limite réelle.

Redéfinissez cette méthode si la marge de cinq secondes ne convient pas à votre
étape la plus longue.

---

## 6.8 Écrire sa fabrique

Le squelette en donne un exemple complet. La structure :

```php
final class Fabrique extends FabriqueBase
{
    // 1. Les definitions viennent de routes/api.php ; les fabriques concretes restent ici.
    public function routeur(): Routeur
    {
        $routeur = new Routeur((string) (getenv('BASE_URI') ?: ''));

        $this->definirService(ControleurFacture::class, function () {
            return new ControleurFacture($this->consulterFacture(), new PresentateurFacture());
        });
        $this->definirService(PresentateurFacture::class, function () {
            return new PresentateurFacture();
        });

        foreach (require dirname(__DIR__) . '/routes/api.php' as $definition) {
            $prefixe = ucfirst($definition['service']);
            $idControleur = __NAMESPACE__ . '\\Presentation\\Controleur\\Controleur' . $prefixe;
            $idPresentateur = __NAMESPACE__ . '\\Presentation\\Presentateur\\Presentateur' . $prefixe;

            $routeur->ajouter(
                $definition['chemin'],
                function () use ($idControleur) {
                    return $this->resoudreService($idControleur);
                },
                function () use ($idPresentateur) {
                    return $this->resoudreService($idPresentateur);
                },
                $definition['methodes']
            );
        }

        return $routeur;
    }

    // 2. La connexion — décrite, pas ouverte
    protected function decrireConnexion(): FabriqueConnexion
    {
        $base = (string) (getenv('DB_BASE') ?: 'exemple');
        return new FabriqueConnexion(
            'mysql:host=' . (getenv('DB_HOTE') ?: '127.0.0.1') . ';dbname=' . $base . ';charset=utf8mb4',
            (string) getenv('DB_UTILISATEUR'),
            (string) getenv('DB_MOT_DE_PASSE'),
            $base                                  // ← le USE explicite
        );
    }

    // 3. Les cas d'usage — non mémoïsés : un par requête suffit
    private function consulterFacture(): ConsulterFacture
    {
        return new ConsulterFacture($this->authentification(), $this->factures());
    }

    // 4. Les services — mémoïsés
    private function factures(): DepotFactureInterface
    {
        return $this->partage('factures', function () {
            return new DepotFacturePdo($this->connexion());
        });
    }
}
```

**Cas d'usage non mémoïsés, services mémoïsés.** Un cas d'usage est bon marché et
sans état ; un dépôt porte la connexion, il doit être unique.

---

## 6.9 La configuration

Le squelette fournit un fichier `.env.example`. Composer le copie vers `.env`
lors de la création du projet. Le fichier `.env` est ignoré par Git ; il contient
les valeurs propres à chaque environnement.

    BASE_URI=
    DB_HOTE=127.0.0.1
    DB_BASE=exemple
    DB_UTILISATEUR=root
    DB_MOT_DE_PASSE=
    JWT_SECRET=

Le chargeur du squelette lit ce fichier sans dépendance externe. Une variable
déjà fournie par Apache, le vhost ou le système reste prioritaire sur `.env`.
En production, définissez les secrets directement dans l'environnement du
serveur.

`JWT_SECRET` reste volontairement vide dans le modèle. Générez une clé aléatoire
et renseignez-la avant d'utiliser l'authentification. Sans cette clé, le
squelette refuse de construire le service de jetons ; aucun secret de secours
n'est utilisé.
