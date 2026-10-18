# 9. Le squelette

`squelette/` — un projet complet et fonctionnel, à copier pour démarrer. Deux
endpoints : `POST /login` et `GET /facture`.

**Il n'est pas là pour être gardé tel quel : il est là pour montrer où va quoi.**
Son domaine (facturation) est destiné à être remplacé, pas étendu.

```
squelette/
├── README.md, composer.json, autoload.php
├── public/
│   ├── index.php
│   └── .htaccess
├── src/
│   ├── Fabrique.php
│   ├── Domain/
│   │   ├── ErreurFacturation.php
│   │   ├── Entite/Facture.php
│   │   └── Contrat/
│   │       ├── DepotFactureInterface.php
│   │       └── DepotUtilisateurInterface.php
│   ├── Application/
│   │   ├── Connecter.php
│   │   └── ConsulterFacture.php
│   ├── Infrastructure/
│   │   ├── DepotFacturePdo.php
│   │   ├── DepotUtilisateurPdo.php
│   │   └── ResolveurDepot.php
│   └── Presentation/
│       ├── Controleur/
│       │   ├── ControleurConnexion.php
│       │   └── ControleurFacture.php
│       └── Presentateur/
│           ├── PresentateurConnexion.php
│           └── PresentateurFacture.php
└── tests/architecture/
    ├── doubles.php
    └── equivalence.php
```

---

## 9.1 `public/index.php`

**Tout passe par ici, et c'est le seul fichier exposé au web.** Les sources sont
hors de `public/` : un fichier qui n'est pas servi ne peut pas être lu par
erreur, quelle que soit la configuration du serveur.

```php
require __DIR__ . '/../autoload.php';

ini_set('display_errors', '0');   // expose les chemins du serveur
ini_set('log_errors', '1');

$requete    = Requete::depuisGlobales();
$aiguillage = new Aiguillage(
    (new Fabrique($requete))->routeur(),
    new PresentateurCommun(),
    function (Throwable $e) { error_log(/* … */); }
);
$aiguillage->servir($requete)->envoyer();
```

> **⚠ Ce fichier ne doit jamais grossir.** S'il commence à contenir des `if` sur
> le chemin demandé, la logique est en train de remonter du routeur vers lui.

`display_errors` à `0` n'est pas une coquetterie : le message d'une exception PDO
contient l'hôte et parfois les identifiants de connexion.

---

## 9.2 `public/.htaccess`

Trois blocs :

**1. La réécriture** — tout vers `index.php`, sauf les fichiers réellement
présents.

**2. La récupération d'`Authorization`** :

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

> **⚠** Apache retire cet en-tête avant PHP quand `CGIPassAuth` est désactivé —
> configuration fréquente en mutualisé. Sans ces deux lignes, **tous** les
> endpoints authentifiés répondent « token introuvable » alors que le client
> l'envoie bien.

**3. La configuration.** Composer copie `.env.example` vers `.env` à la création
du projet. Renseignez les variables locales dans `.env` ; ce fichier est ignoré
par Git. En production, les variables du serveur ou du vhost sont prioritaires.

---

## 9.3 `src/Domain/Entite/Facture.php`

```php
final class Facture
{
    const EN_ATTENTE = 'en_attente';
    const PAYEE      = 'payee';
    const ANNULEE    = 'annulee';

    public function __construct(string $numero, int $montantCentimes,
                                string $statut, string $dateEmission);
    public function numero(): string;
    public function montantCentimes(): int;
    public function statut(): string;
    public function dateEmission(): string;
    public function estPayable(): bool;
    public function montantAffiche(): string;
}
```

**Deux choses à remarquer, et elles valent pour toutes vos entités.**

**1. Le montant est un entier, en centimes.**

> **⚠** Un `float` ne représente pas exactement 0,10 : additionnez-en assez et
> le total se met à finir par des chiffres impossibles. En comptabilité, cet
> écart est un incident.

`montantAffiche()` fait la conversion pour l'API — le domaine compte en
centimes, pas l'API.

**2. Aucune annotation, aucun lien vers la base.** L'entité ignore qu'elle est
stockée, et donc comment. C'est ce qui permet de changer de schéma, ou de réunir
deux tables en une entité, sans toucher aux cas d’usage.

**`estPayable()` est une règle métier, placée dans l'entité.** La même question
sera posée par le paiement, par la relance et par l'export ; une seule réponse,
ici, au lieu de trois `if` qui finiront par diverger.

**Les invariants se défendent dans le constructeur** : une facture ne peut pas
exister sans numéro, avec un montant négatif, un statut inconnu ou une date
invalide au format `AAAA-MM-JJ`. Mieux vaut échouer à la construire que la
promener à moitié remplie dans toute l'application.

---

## 9.4 `src/Domain/ErreurFacturation.php`

```php
final class ErreurFacturation extends ErreurMetier
{
    const FACTURE_INTROUVABLE = 'facture_introuvable';
    const FACTURE_DEJA_PAYEE  = 'facture_deja_payee';

    public static function factureIntrouvable(string $numero): self;
    public static function factureDejaPayee(string $numero): self;
}
```

Elle hérite des dix types communs et n'ajoute que ce que la facturation sait dire
de plus.

**Remarquez qu'il n'y a pas de code HTTP ici.** Le domaine ignore HTTP ; c'est le
présentateur qui décide qu'une facture introuvable vaut 404.

**Le numéro va dans le `detail`**, donc au journal — pas forcément dans la
réponse. Le présentateur tranche.

---

## 9.5 `src/Domain/Contrat/` — les deux dépôts

```php
interface DepotFactureInterface extends DepotInterface
{
    public function trouverParNumero(string $numero): ?Facture;
    public function impayeesDe(int $abonneId): array;
}

interface DepotUtilisateurInterface extends DepotInterface
{
    public function parIdentifiants(string $identifiant, string $motDePasse): ?Identite;
    public function parId(int $id): ?Identite;
}
```

**Le contrat est écrit par celui qui consomme, pas par celui qui implémente.**
C'est l'inversion de dépendance : ces interfaces vivent dans le domaine,
l'implémentation SQL vit dans l'infrastructure.

**La vérification du mot de passe est dans l'implémentation, pas dans le
contrat** : le domaine demande « qui est-ce, avec ces identifiants ? » et ne veut
connaître ni l'algorithme de hachage, ni la forme de la table.

**Aucune méthode ne rend le mot de passe, même haché.** Ce qui ne sort pas du
dépôt ne peut pas se retrouver dans un journal ou une réponse.

---

## 9.6 `src/Application/Connecter.php`

```php
final class Connecter
{
    public function __construct(DepotUtilisateurInterface $utilisateurs,
                                JetonInterface $jetons,
                                JournalInterface $journal,
                                int $dureeJeton = 3600);
    public function executer(string $identifiant, string $motDePasse): array;
}
```

**Anatomie d'un cas d’usage** — le modèle vaut pour tous les autres :

- il reçoit des **interfaces**, jamais des objets concrets ;
- il ne connaît ni HTTP, ni SQL, ni JSON ;
- il rend une donnée ou lève une `ErreurMetier`, et rien d'autre ;
- il tient en un écran. Au-delà, il fait le travail de deux.

Aucun `echo`, aucun `header`, aucun code HTTP : c'est ce qui permet de l'éprouver
entièrement en mémoire, avec des doubles.

**Trois décisions y sont écrites explicitement** :

**1. Une seule erreur pour deux cas.**

```php
if ($identite === null) {
    throw ErreurMetier::identifiantsInvalides();
}
```

> **⚠** Distinguer « compte inconnu » de « mot de passe faux » permet d'énumérer
> les comptes existants.

**2. Le jeton ne transporte qu'un identifiant.**

```php
$jeton = $this->jetons->emettre(['sub' => $identite->id()], $this->dureeJeton);
```

La charge est lisible par tous ; le nom, le rôle ou l'adresse n'ont rien à y
faire, et seront de toute façon relus en base à chaque requête.

**3. Le journal ne bloque pas la connexion.**

```php
// Le journal rend false plutôt que de lever.
// ICI, ON CHOISIT DE POURSUIVRE : refuser une connexion valide parce que
// sa trace n'a pas pu s'écrire serait pire que la trace perdue.
$this->journal->enregistrer($identite->id(), 'connexion');
```

**Ce choix doit être écrit, sinon personne ne saura qu'il a été fait.**

---

## 9.7 `src/Application/ConsulterFacture.php`

```php
final class ConsulterFacture
{
    public function __construct(AuthentificationInterface $authentification,
                                DepotFactureInterface $factures);
    public function executer(string $numero): Facture;
}
```

**L'ordre des trois étapes n'est pas arbitraire** :

1. valider l'entrée — inutile d'authentifier pour une requête vide ;
2. identifier l'appelant — **avant tout accès aux données** ;
3. lire.

> **⚠** Inverser 2 et 3, c'est permettre à un appelant anonyme d'apprendre, au
> choix des réponses, quelles factures existent.

Le résultat de `identifier()` n'est pas utilisé ici, mais l'appel, lui, est
indispensable. La suite d'équivalence le vérifie en comptant les accès au dépôt.

---

## 9.8 `src/Infrastructure/` — les trois implémentations

### `DepotFacturePdo`

**Tout le SQL du projet vit dans cette couche.** Un cas d’usage qui contient un
`SELECT` ne peut plus être testé sans base — et c'est toujours par là qu'une
architecture propre commence à se défaire.

`hydrater()` est la frontière : au-dessus on parle en `Facture`, en dessous en
lignes de table. Elle convertit aussi les types, puisque PDO rend des chaînes
même pour les colonnes numériques.

### `DepotUtilisateurPdo`

**Deux points de sécurité, et aucun n'est négociable.**

**1. Le mot de passe n'est jamais comparé en SQL.**

> **⚠** Un `WHERE mot_de_passe = :mdp` suppose un stockage en clair ou un
> hachage réversible, et confie la comparaison au moteur. On charge le hachage,
> et `password_verify` — qui compare en temps constant — tranche.

Un hachage factice est tout de même vérifié sur compte inconnu : sans cela, un
compte inexistant répond nettement plus vite qu'un mot de passe faux, ce qui
suffit à énumérer les comptes.

**2. Le compte désactivé est filtré dans `parId()`.** C'est ce qui rend un jeton
encore valide inopérant dès la désactivation, au lieu d'attendre son expiration.

### `ResolveurDepot`

Cinq lignes, et pourtant c'est elle qui fait la différence entre « le jeton dit
que c'est l'utilisateur 42 » et « l'utilisateur 42 existe toujours et est actif ».

> **⚠** La tentation permanente est de la court-circuiter — l'identifiant est
> dans le jeton, pourquoi relire la base ? **Parce qu'un jeton émis ce matin
> parle d'un compte tel qu'il était ce matin.**

---

## 9.9 `src/Presentation/Controleur/`

```php
final class ControleurFacture
{
    public function __construct(ConsulterFacture $consulter, PresentateurFacture $presentateur);
    public function __invoke(Requete $requete): ReponseHttp;
}
```

**Un contrôleur ne décide rien.** Il extrait, il appelle, il présente. Trois
lignes utiles, et c'est normal : la règle métier est dans le cas d’usage, le
format de réponse dans le présentateur.

**Il ne contient aucun `try`.** Les `ErreurMetier` remontent jusqu'à
`Aiguillage`, qui les confie au présentateur de la route. Attraper ici
dupliquerait ce mécanisme, avec le risque de le faire différemment d'un endpoint
à l'autre.

**`__invoke()`** permet de passer le contrôleur directement comme action de
route : `$action($requete)`.

---

## 9.10 `src/Presentation/Presentateur/`

Chacun répond à lui seul à la question **« que rend cet endpoint, dans tous les
cas ? »**. C'est le but : le contrat tient sur un écran, se relit avant une mise
en production, et se teste sans base ni serveur.

`PresentateurFacture` illustre un détail qui compte :

```php
case ErreurFacturation::FACTURE_INTROUVABLE:
    // Le numéro demandé n'est PAS répété dans le message : il vient de
    // l'appelant, et le renvoyer tel quel expose au moindre client qui
    // l'afficherait sans échapper.
    return $this->echec(404, 'erreur', 'facture introuvable');
```

Un contrôle d'équivalence envoie `<script>` comme numéro et vérifie qu'il ne
ressort pas.

---

## 9.11 `src/Fabrique.php`

Voir [`06-composition.md`](06-composition.md) § 6.7 pour le détail. Trois points
propres au squelette :

- chaque route déclare **son** présentateur ;
- l'authentification est **différée**, parce que la construire touche la base ;
- JWT_SECRET n'a pas de valeur de repli : renseignez-le dans .env ou dans l'environnement du serveur, sinon le service de jetons refuse de demarrer.

---

## 9.12 `tests/architecture/`

### `doubles.php`

Les doubles **propres au projet** : `DepotFactureFactice`,
`DepotUtilisateurFactice`. Les doubles génériques — journal, surveillance, jeton,
authentification — viennent de la bibliothèque (`PhpCleanCode\Test`).

`DepotUtilisateurFactice::desactiver()` simule le cas qui compte : le jeton reste
valide, le compte non.

### `equivalence.php`

**53 contrôles**, sans base, sans serveur, sans réseau. Tout tourne en mémoire,
en une fraction de seconde — c'est ce qui permet de la relancer après chaque
modification. Une suite qu'on ne relance pas ne protège de rien.

| Section | Contrôles | Ce qui est vérifié |
|---|---|---|
| 1. Domaine | 7 | conversion des centimes, règle `estPayable`, invariant du constructeur |
| 2. Cas d'usage | 10 | cas nominal, refus, **ordre authentification/lecture**, non-énumération des comptes, journal en échec |
| 3. Contrat HTTP | 21 | codes, clés, **ordre des clés**, non-fuite du détail technique, indiscernabilité des jetons invalides |
| 4. Routage | 15 | préfixe retiré, casse ignorée, 404, 405, panne de câblage rendue par le bon présentateur |

**La section 3 est celle qui protège les consommateurs tiers.** Un refactoring
peut tout réorganiser en dessous : tant que ces contrôles passent, aucun client
n'a besoin d'être prévenu.

---

## 9.13 Essayer sans Apache

```bash
cd squelette
JWT_SECRET=test BASE_URI= php -S 127.0.0.1:8000 -t public public/index.php

curl "http://127.0.0.1:8000/facture?numero=F-1"
# {"statut":"erreur","message":"token introuvable"}
```

Avec une base volontairement injoignable, la fumigation complète donne :

| Requête | Réponse |
|---|---|
| `GET /facture?numero=F-1` | `400 {"statut":"erreur","message":"token introuvable"}` |
| `GET /facture` + jeton bidon | `401 {"statut":"erreur","message":"token invalide"}` |
| `GET /inconnu` | `404 {"statut":"erreur","message":"ressource introuvable"}` |
| `GET /login` | `405 {"statut":"erreur","message":"methode non autorisee"}` |
| `POST /login` corps vide | `400 {"statut":"erreur","message":"parametre manquant : …"}` |
| `POST /login` identifiants | `500 {"statut":"erreur","message":"erreur interne"}` |

**La première ligne est celle qui valide tout le reste** : base morte, et
pourtant le diagnostic juste. C'est la paresse de `FabriqueConnexion` et
d'`AuthentificationDifferee`, vérifiée de bout en bout.
