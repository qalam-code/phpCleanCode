# 2. Le domaine et les ports de l'application

Le domaine porte les concepts et contrats métier. Les interfaces requises par
les cas d'usage sont des ports dans `src/Application/Port/` : elles restent
dans la couche intérieure, mais ne sont pas des concepts du domaine.

```
Domain/
├── ErreurMetier.php
├── Entite/
│   └── Identite.php
└── Contrat/
    └── DepotInterface.php

Application/Port/
├── AuthentificationInterface.php
├── JetonInterface.php
├── JournalInterface.php
├── ResolveurActeurInterface.php
└── SurveillanceInterface.php
```

---

## 2.1 `ErreurMetier`

**Signature**

```php
class ErreurMetier extends Exception
{
    const PARAMETRE_MANQUANT     = 'parametre_manquant';
    const RESSOURCE_INTROUVABLE  = 'ressource_introuvable';
    const ECHEC_ECRITURE         = 'echec_ecriture';
    const IDENTIFIANTS_INVALIDES = 'identifiants_invalides';
    const JETON_ABSENT           = 'jeton_absent';
    const JETON_MALFORME         = 'jeton_malforme';
    const JETON_MAL_SIGNE        = 'jeton_mal_signe';
    const JETON_EXPIRE           = 'jeton_expire';
    const ACTEUR_INTROUVABLE     = 'acteur_introuvable';
    const ECHEC_JOURNALISATION   = 'echec_journalisation';

    public function __construct(string $type, string $detail = '');
    public function type(): string;
    public function estDeType(string $type): bool;

    public static function parametreManquant(string $parametres): self;
    public static function ressourceIntrouvable(string $quoi): self;
    public static function echecEcriture(string $detail): self;
    public static function identifiantsInvalides(): self;
    public static function jetonAbsent(): self;
    public static function jetonMalforme(): self;
    public static function jetonMalSigne(): self;
    public static function jetonExpire(): self;
    public static function acteurIntrouvable(): self;
    public static function echecJournalisation(): self;
}
```

**Rôle** — Une seule exception pour tout le domaine, qualifiée par un *type*.

**Pourquoi** — Le domaine dit ce qui ne va pas ; il ne dit pas quel code HTTP
répondre. C'est le présentateur, tout en haut, qui traduit un type en code et
en message. Trois conséquences :

1. Changer un libellé ne touche jamais le domaine.
2. Un même type peut se traduire différemment selon l'endpoint.
3. Une classe d'exception par cas produirait vingt classes vides et autant de
   `catch` à tenir à jour. Un type suffit.

**Étendre** — Votre projet ajoute ses types dans une sous-classe :

```php
final class ErreurFacturation extends ErreurMetier
{
    const FACTURE_DEJA_PAYEE = 'facture_deja_payee';

    public static function factureDejaPayee(string $numero): self
    {
        return new self(self::FACTURE_DEJA_PAYEE, 'facture ' . $numero);
    }
}
```

Les dix types de base restent disponibles, et `PresentateurCommun` sait déjà les
traduire : vous n'écrivez que ce qui est propre à votre métier.

**Les fabriques nommées** — `ErreurMetier::jetonExpire()` plutôt que
`new ErreurMetier('jeton_expire', '…')`. Elles seules garantissent qu'un type
connu va avec le bon message, et elles se retrouvent d'un coup d'œil dans l'IDE.

> **⚠ Le constructeur est public — par obligation, pas par intention.**
>
> Il devrait être privé : seules les fabriques devraient construire une erreur.
> PHP l'interdit. Une méthode qui en redéfinit une autre ne peut pas réduire sa
> visibilité, et `Exception::__construct()` est publique. Le déclarer privé fait
> échouer **la déclaration de la classe entière** :
>
> ```
> Fatal error: Access level to ErreurMetier::__construct()
> must be public (as in class Exception)
> ```
>
> PHP 8 a relâché la règle pour les constructeurs. Ne vous y fiez pas : le code
> doit se déclarer sous la version la plus ancienne que vous visez. Voir
> [`11-pieges.md`](11-pieges.md), piège n° 1 — celui-ci a mis une API entière
> par terre.

**Le `detail` n'est pas un message d'API.** `getMessage()` contient le détail
technique (`SQLSTATE[HY000] Connection refused`, un numéro de facture, un nom de
verrou). Il va au journal. Le présentateur décide, cas par cas, ce qui remonte
au consommateur — le plus souvent : rien de tout ça.

---

## 2.2 `Entite\Identite`

**Signature**

```php
final class Identite
{
    public function __construct(int $id, string $nom);
    public function id(): int;
    public function nom(): string;
}
```

**Rôle** — Le consommateur de l'API, une fois authentifié.

**Pourquoi** — C'est la **seule source d'identité du système**. Aucune méthode
ne construit une `Identite` depuis une requête : elle ne peut venir que d'un
jeton vérifié (`Authentificateur`) ou d'une authentification réussie
(`DepotUtilisateur::parIdentifiants`).

> **⚠** Cette règle n'est pas théorique. Accepter un identifiant d'utilisateur
> transmis dans le corps d'une requête — et court-circuiter le jeton quand il est
> présent — est une faille classique : n'importe quel appelant authentifié agit
> alors au nom de n'importe qui. Sur un système de paiement, cela revient à
> imputer l'argent à la mauvaise caisse.

**Volontairement minimale.** Ni rôle, ni permissions, ni adresse. Ce dont un cas
d'usage a besoin pour tracer et imputer une action tient en deux champs. Si
votre projet a besoin de rôles, ajoutez-les dans **votre** entité utilisateur,
côté projet — pas ici.

---

## 2.3 `Application\Port\JetonInterface`

**Signature**

```php
interface JetonInterface
{
    public function emettre(array $charge, int $dureeSecondes): string;
    public function verifier(string $jeton): array;   // @throws ErreurMetier
}
```

**Rôle** — Émettre et vérifier le jeton d'accès.

**Pourquoi** — Le domaine ne sait pas ce qu'est un JWT. Il sait qu'il existe un
moyen de transformer une identité en chaîne, et cette chaîne en identité. La
bibliothèque en fournit une implémentation HS256 (`JetonJwt`), mais rien
n'oblige à l'utiliser : jeton opaque en base, PASETO, session — seul ce contrat
est connu du reste du code.

**Ce que `verifier()` lève** — `JETON_MALFORME`, `JETON_MAL_SIGNE`,
`JETON_EXPIRE`. Elle ne retourne jamais `false` : une vérification qui échoue
est une erreur, pas une valeur.

---

## 2.4 `Application\Port\AuthentificationInterface`

**Signature**

```php
interface AuthentificationInterface
{
    public function identifier(): Identite;   // @throws ErreurMetier
}
```

**Rôle** — Répondre à « qui appelle ? ».

**Pourquoi** — Un cas d'usage qui a besoin de savoir à qui imputer une action
dépend de cette interface, et de rien d'autre. Il ignore s'il y a un jeton, un
en-tête, un cookie ou une session derrière.

> **⚠ Implémentez-la paresseusement.** La racine de composition câble
> l'authentificateur dans tous les cas d'usage. Si sa construction ouvre la
> connexion à la base, une base injoignable fait échouer l'endpoint *avant*
> qu'il ait pu répondre « jeton absent » à une requête qui n'en portait pas. Le
> diagnostic rendu au consommateur devient faux, et le défaut ne se voit que le
> jour où la base tombe. D'où `AuthentificationDifferee` — voir
> [`03-infrastructure.md`](03-infrastructure.md) § 3.6.

**Ce qu'elle lève** — `JETON_ABSENT`, `JETON_MALFORME`, `JETON_MAL_SIGNE`,
`JETON_EXPIRE`, `ACTEUR_INTROUVABLE`.

---

## 2.5 `Application\Port\JournalInterface`

**Signature**

```php
interface JournalInterface
{
    public function enregistrer(int $acteurId, string $action, string $detail = ''): bool;
}
```

**Rôle** — Tracer une action.

**Pourquoi un booléen, et pas une exception** — C'est la décision de conception
la plus importante de ce fichier.

Un journal qui lève une exception fait échouer l'opération qu'il devait
seulement accompagner : la transaction est passée, l'argent a bougé, et le
consommateur reçoit une erreur parce que l'écriture du journal a échoué.

Retourner `false` laisse l'appelant décider. Un cas d'usage où la trace est une
obligation réglementaire traduira `false` en erreur ; ailleurs, il l'ignorera.
Dans les deux cas **le choix est visible dans le cas d'usage**, et non enfoui
dans une implémentation.

```php
// Le choix, écrit noir sur blanc :
// refuser une connexion valide parce que sa trace n'a pas pu s'écrire
// serait pire que la trace perdue.
$this->journal->enregistrer($identite->id(), 'connexion');
```

> **⚠** La contrepartie doit être assumée : si personne ne lit ce booléen, une
> table de journal pleine ou verrouillée se traduit par une **perte de traces
> totalement silencieuse**.

---

## 2.6 `Application\Port\SurveillanceInterface`

**Signature**

```php
interface SurveillanceInterface
{
    public function tempsRestant(): bool;
    public function ecoule(): float;
}
```

**Rôle** — Garde-temps d'un traitement.

**Pourquoi** — Utile dès qu'un cas d'usage appelle un service tiers ou boucle
sur un ensemble de taille inconnue. Il consulte la surveillance entre deux
étapes et s'arrête proprement, au lieu de se faire tuer par
`max_execution_time` **au milieu d'une écriture**.

```php
foreach ($factures as $facture) {
    if (!$this->surveillance->tempsRestant()) {
        break;   // arrêt propre, état cohérent
    }
    $this->payer($facture);
}
```

**`tempsRestant()` ne répond pas « reste-t-il du temps ? »** mais « reste-t-il
au moins une étape de temps ? ». La différence est la marge — voir
[`03-infrastructure.md`](03-infrastructure.md) § 3.5.

---

## 2.7 `Application\Port\ResolveurActeurInterface`

**Signature**

```php
interface ResolveurActeurInterface
{
    public function resoudre(array $charge): ?Identite;   // null = plus personne
}
```

**Rôle** — Passer du contenu d'un jeton à un utilisateur réel.

**Pourquoi c'est une étape séparée de la vérification du jeton** — Un jeton peut
être parfaitement signé et non expiré, et pourtant désigner un compte supprimé
ou désactivé depuis son émission. Sans cette étape, le système fait confiance à
une photographie vieille de plusieurs heures.

> **⚠** L'implémentation naïve — prendre l'identifiant écrit dans la charge et
> s'en contenter — fait disparaître ce contrôle, sans que rien ne le signale.
> **Allez chercher l'utilisateur.**

---

## 2.8 `Contrat\DepotInterface`

**Signature**

```php
interface DepotInterface
{
    // volontairement vide
}
```

**Rôle** — Marqueur. N'impose aucune méthode.

**Pourquoi vide** — Un dépôt se décrit par le besoin du domaine, pas par une API
générique :

```php
interface DepotFactureInterface extends DepotInterface
{
    public function trouverParNumero(string $numero): ?Facture;
    public function impayeesDe(int $abonneId): array;
}
```

Une interface commune à `find` / `save` / `delete` ferait fuiter la base de
données dans le domaine : le cas d'usage se mettrait à raisonner en lignes et en
clés primaires au lieu de factures et d'abonnés, et toute optimisation SQL
deviendrait impossible sans changer le contrat.

**Le vocabulaire est le test.** `impayeesDe($abonne)`, pas `select($where)`. Si
les méthodes de vos dépôts ressemblent à du SQL traduit, la base a remonté dans
le domaine.

Ce marqueur ne sert donc qu'à une chose : rendre les dépôts repérables, pour un
contrôle d'architecture ou un scan d'autochargement.
