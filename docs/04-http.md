# 4. La couche Http

`src/Http/` contient aussi `FichierTeleverse`. L'entr�e de l'application : lire la requ�te,
trouver la route, appeler, ne jamais laisser une exception s'�chapper.

---

## 4.1 `Requete`

**Signature**

```php
final class Requete
{
    public function __construct(
        string $methode,
        string $chemin,
        array $corps = [],
        array $requeteUrl = [],
        array $entetes = [],
        bool $corpsInvalide = false,
        array $fichiers = []
    );
    public static function depuisGlobales(): self;

    public function methode(): string;                      // majuscules
    public function chemin(): string;
    public function corps(): array;
    public function corpsInvalide(): bool;
    public function fichier(string $cle);                  // FichierTeleverse|null|tableaux imbriques
    public function fichiers(): array;
    public function parametre(string $cle, $defaut = null);  // corps, puis query
    public function manquants(array $cles): array;
    public function entete(string $nom);            // insensible � la casse
}
```

**R�le** � La requ�te entrante, lue une fois pour toutes.

**Pourquoi** � Les superglobales sont lues dans `depuisGlobales()` **et nulle
part ailleurs**. C'est ce qui rend tout le reste testable : un test construit
une `Requete` avec les valeurs qu'il veut, sans toucher `$_POST` ni simuler
`php://input`.

```php
$requete = new Requete('GET', '/facture', [], ['numero' => 'F-2026-001']);
```

Les formulaires multipart sont exposes separement du corps :

```php
$fichier = $requete->fichier('document');
if ($fichier !== null && $fichier->estRecu()) {
    $nomDeclare = $fichier->nomOriginal();
    $cheminTemporaire = $fichier->cheminTemporaire();
    // Verifier le contenu et choisir un nom serveur avant de le stocker.
}
```

`depuisGlobales()` convertit `$_FILES` en objets `FichierTeleverse`. Un champ
`name="documents[]"` devient un tableau d'objets accessible avec
`fichier('documents')`. Les noms, types MIME et tailles fournis par le client
ne sont pas fiables ; `typeDeclare()` n'est qu'une indication. Le framework
expose le chemin temporaire et le code d'erreur PHP, mais ne deplace ni ne
stocke le fichier. Cette responsabilite revient a un adaptateur choisi par
l'application et ne doit pas etre ajoutee au domaine.

**Les donn�es sont rendues brutes, sans �chappement.** �chapper � l'entr�e
corrompt ce qu'on enregistre et ne prot�ge de rien : c'est � la **sortie** �
requ�te pr�par�e pour SQL, `json_encode` pour la r�ponse � que l'�chappement a
un sens, parce que la destination est alors connue.

**`parametre()` cherche d'abord dans le corps, puis dans la query string.** Un
endpoint accepte ainsi les deux formes sans que le contr�leur ait � s'en
soucier.

**`manquants()` traite les cha�nes vides comme absentes** :

```php
$absents = $requete->manquants(['identifiant', 'mot_de_passe']);
if ($absents !== []) {
    throw ErreurMetier::parametreManquant(implode(', ', $absents));
}
```

Une valeur vide pass�e par erreur n'est pas une valeur. L'ordre du tableau rendu
est celui demand�, pour que le message d'erreur soit stable.

**`depuisGlobales()` g�re trois cas particuliers** qui co�tent chacun une demi-
journ�e quand on ne les conna�t pas :

1. JSON dans le flux de requete : un objet JSON devient le corps. Un JSON mal forme ou une valeur qui n est pas un objet recoit une reponse 400 via le presentateur de la route. Les formulaires classiques restent acceptes.
2. **`getallheaders()` manque** sous certaines configurations (php-fpm avec
   nginx, CLI), et c'est pr�cis�ment l� qu'`Authorization` se perd. Repli sur un
   parcours de `$_SERVER['HTTP_*']`.
3. **Apache masque `Authorization`** quand `CGIPassAuth` est d�sactiv� �
   configuration fr�quente en mutualis�. La r��criture de `.htaccess` le remet
   dans `REDIRECT_HTTP_AUTHORIZATION`, que `depuisGlobales()` sait relire.

> **?** Sans le point 3, tous les endpoints authentifi�s r�pondent � token
> introuvable � alors que le client envoie bien son jeton. La ligne
> correspondante du `.htaccess` du squelette n'est pas d�corative.

---

## 4.2 `Routeur`

**Signature**

```php
final class Routeur
{
    public function __construct(string $base = '');
    public function ajouter(
        string $chemin,
        callable $action,         // (): callable � re�oit une Requete, rend une ReponseHttp
        callable $presentateur,   // (): PresentateurAbstrait
        array $methodes = []      // vide = toutes
    );
    public function resoudre(string $chemin);
    public function chemins(): array;
}
```

**R�le** � Table des routes. Ne fait que r�soudre ; c'est `Aiguillage` qui
ex�cute.

Deux d�clarations dont les chemins deviennent identiques apr�s normalisation
sont refus�es avec `InvalidArgumentException`, au lieu de remplacer
silencieusement la route pr�c�dente.

**Chaque route porte son propre pr�sentateur**, et c'est la d�cision de
conception la plus importante de cette classe.

> **?** Avec un pr�sentateur unique pour toute l'application, une panne survenue
> pendant le c�blage d'un endpoint est rendue par le format d'un autre. Vu en
> production : une base injoignable r�pondait **� identifiants invalides �** �
> chaque appel, parce que le pr�sentateur de secours �tait celui de la
> connexion. Les consommateurs ont cherch� du c�t� de leurs identifiants pendant
> que le probl�me �tait ailleurs. Voir [`11-pieges.md`](11-pieges.md), pi�ge
> n� 3.

**Les deux entr�es sont des fabriques, pas des objets.** Rien n'est construit
pour les routes qui ne sont pas appel�es. Sur sept endpoints, une requ�te n'en
c�ble qu'un.

**Normalisation des chemins** � `/api/Paye-Facture/` et `paye-facture` d�signent
la m�me route :

- le pr�fixe d'installation (`$base`) est retir� seulement s'il correspond � un segment complet ;
- les barres de d�but et de fin sont supprim�es ;
- la casse est ignor�e � un consommateur qui �crit `/Login` recevrait sinon un
  404 incompr�hensible.

**`chemins()`** rend l'inventaire des routes d�clar�es. Utile � un test
d'inventaire : *� la liste des endpoints est-elle toujours celle-ci ? �* � une
route ajout�e par inadvertance se voit alors imm�diatement.

**Utilisation**

```php
$routeur = new Routeur('/mon-projet');

$routeur->ajouter(
    'facture',
    function () { return new ControleurFacture($this->consulterFacture(), new PresentateurFacture()); },
    function () { return new PresentateurFacture(); },
    ['GET', 'POST']
);
```

---

## 4.3 `Aiguillage`

**Signature**

```php
final class Aiguillage
{
    public function __construct(
        Routeur $routeur,
        PresentateurAbstrait $secours,
        $journaliseur = null   // (Throwable): void � NE DOIT PAS LEVER
    );
    public function servir(Requete $requete): ReponseHttp;
}
```

**R�le** � Point d'entr�e unique : une requ�te entre, une r�ponse sort.

**Aucune exception ne sort d'ici.** Le `catch (Throwable)` final n'est pas un
tapis sous lequel on pousse les erreurs : c'est la promesse qu'un consommateur
recevra **toujours** du JSON et un code HTTP, jamais une page blanche ni une
trace PHP r�v�lant les chemins du serveur.

Ce que le `catch` ne doit pas devenir : un endroit o� l'erreur dispara�t. D'o�
le journaliseur � ce qui n'est pas rendu au consommateur doit �tre �crit quelque
part, sinon l'incident est invisible.

**D�roul� de `servir()`**

| �tape | Situation | R�ponse |
|---|---|---|
| 1 | chemin inconnu | `$secours->traduire(RESSOURCE_INTROUVABLE)` ? 404 |
| 2 | methode non autorisee | 405 avec `Allow` ; le presentateur de route ajuste le corps |
| 3 | corps JSON invalide ou valeur non objet | 400 via le presentateur de route |
| 4 | presentateur de route impossible a construire | secours, puis reponse 500 garantie |
| 5 | ErreurMetier du controleur ou cas d usage | traduction par le presentateur de route |
| 6 | autre exception ou presentateur en echec | journalise, presentateur de secours, puis 500 fixe si besoin |

**La construction du contr�leur est dans le `try`, d�lib�r�ment.** Un c�blage qui
�choue � base injoignable, configuration absente � est un incident comme un
autre, et doit �tre rendu par le pr�sentateur de la route appel�e.

**Le pr�sentateur, lui, est construit avant le `try`** : sans lui, rien ne peut
�tre rendu correctement. S'il �choue lui-m�me, le secours prend le relais � il
n'a, lui, aucune d�pendance.

**Le journaliseur est prot�g�** par son propre `try` : un journal qui tombe ne
doit pas emporter la r�ponse avec lui.

**Utilisation** � c'est tout `public/index.php` :

```php
$requete = Requete::depuisGlobales();

$aiguillage = new Aiguillage(
    (new Fabrique($requete))->routeur(),
    new PresentateurCommun(),
    function (Throwable $e) {
        error_log('[' . get_class($e) . '] ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
);

$aiguillage->servir($requete)->envoyer();
```

POST`.
2. **`getallheaders()` manque** sous certaines configurations (php-fpm avec
   nginx, CLI), et c'est pr�cis�ment l� qu'`Authorization` se perd. Repli sur un
   parcours de `$_SERVER['HTTP_*']`.
3. **Apache masque `Authorization`** quand `CGIPassAuth` est d�sactiv� �
   configuration fr�quente en mutualis�. La r��criture de `.htaccess` le remet
   dans `REDIRECT_HTTP_AUTHORIZATION`, que `depuisGlobales()` sait relire.

> **?** Sans le point 3, tous les endpoints authentifi�s r�pondent � token
> introuvable � alors que le client envoie bien son jeton. La ligne
> correspondante du `.htaccess` du squelette n'est pas d�corative.

---

