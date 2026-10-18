# 10. Recettes

Les gestes courants, dans l'ordre où on les rencontre.

---

## 10.1 Démarrer un projet

```bash
cp -r squelette /chemin/vers/mon-projet
cd /chemin/vers/mon-projet
cp .env.example .env
php tests/architecture/equivalence.php     # doit afficher 43 conformes
```

Vérifiez **avant** de modifier quoi que ce soit : si la suite passe, la copie est
saine, et tout échec ultérieur viendra de vous.

Puis, dans l'ordre :

**1. L'espace de noms.** Remplacez `App\Exemple\` par le vôtre dans `src/`,
`autoload.php` et `composer.json`.

```bash
grep -rl 'App\\Exemple' . | xargs sed -i 's/App\\\\Exemple/App\\\\MonProjet/g'
```

**2. La configuration.** Composer copie .env.example vers .env lors de create-project. Si vous avez copie le squelette a la main, creez .env avec `cp .env.example .env`. Renseignez les acces a la base et generez JWT_SECRET :

> **JWT_SECRET reste vide dans le modele** : generez une valeur aleatoire et renseignez-la dans .env avant d'utiliser l'authentification.
> ```bash
> php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
> ```

**3. Le domaine.** Remplacez `Facture` par vos entités, `ErreurFacturation` par
vos types d'erreur. Supprimez ce qui ne sert pas.

**4. Les routes.** Ajoutez le chemin, le service et les methodes dans routes/api.php. Reliez ensuite ce service aux fabriques du controleur et du presentateur dans src/Fabrique.php.

**5. La suite d'équivalence.** Réécrivez-la au fur et à mesure : elle doit
décrire **votre** contrat, pas celui de la facturation.

---

## 10.2 Ajouter un endpoint

Cinq fichiers, toujours les mêmes, toujours dans cet ordre. Exemple :
`POST /annuler-facture`.

### 1. Le type d'erreur, s'il en faut un

```php
// src/Domain/ErreurFacturation.php
const FACTURE_DEJA_ANNULEE = 'facture_deja_annulee';

public static function factureDejaAnnulee(string $numero): self
{
    return new self(self::FACTURE_DEJA_ANNULEE, 'facture ' . $numero);
}
```

### 2. Le contrat de dépôt, s'il manque une méthode

```php
// src/Domain/Contrat/DepotFactureInterface.php
public function marquerAnnulee(string $numero, int $parActeur): bool;
```

### 3. Le cas d'usage

```php
// src/Application/AnnulerFacture.php
final class AnnulerFacture
{
    public function __construct(
        AuthentificationInterface $authentification,
        DepotFactureInterface $factures,
        JournalInterface $journal
    ) { /* … */ }

    public function executer(string $numero): void
    {
        if ($numero === '') {
            throw ErreurMetier::parametreManquant('numero');
        }

        $identite = $this->authentification->identifier();   // AVANT la lecture

        $facture = $this->factures->trouverParNumero($numero);
        if ($facture === null) {
            throw ErreurFacturation::factureIntrouvable($numero);
        }
        if ($facture->statut() === Facture::ANNULEE) {
            throw ErreurFacturation::factureDejaAnnulee($numero);
        }

        if (!$this->factures->marquerAnnulee($numero, $identite->id())) {
            throw ErreurMetier::echecEcriture('annulation de ' . $numero);
        }

        // Trace obligatoire ici : décidez, et écrivez pourquoi.
        $this->journal->enregistrer($identite->id(), 'annulation', $numero);
    }
}
```

### 4. Le présentateur — le contrat HTTP

```php
// src/Presentation/Presentateur/PresentateurAnnulation.php
final class PresentateurAnnulation extends PresentateurCommun
{
    public function annulee(string $numero): ReponseHttp
    {
        return $this->reponse(200, [
            'statut'  => 'succes',
            'message' => 'facture annulee',
        ]);
    }

    public function traduire(ErreurMetier $erreur): ReponseHttp
    {
        switch ($erreur->type()) {
            case ErreurFacturation::FACTURE_INTROUVABLE:
                return $this->echec(404, 'erreur', 'facture introuvable');
            case ErreurFacturation::FACTURE_DEJA_ANNULEE:
                return $this->echec(409, 'erreur', 'facture deja annulee');
            default:
                return parent::traduire($erreur);
        }
    }
}
```

### 5. Le contrôleur et la route

```php
// src/Presentation/Controleur/ControleurAnnulation.php
public function __invoke(Requete $requete): ReponseHttp
{
    $numero = (string) $requete->parametre('numero', '');
    $this->annuler->executer($numero);
    return $this->presentateur->annulee($numero);
}
```

```php
// routes/api.php
return [
    // ... routes deja declarees
    ['chemin' => 'annuler-facture', 'service' => 'annulation', 'methodes' => ['POST']],
];
```

Dans src/Fabrique.php, enregistrez les deux fabriques explicites dans le conteneur :

```php
$this->definirService(ControleurAnnulation::class, function () {
    return new ControleurAnnulation($this->annulerFacture(), new PresentateurAnnulation());
});
$this->definirService(PresentateurAnnulation::class, function () {
    return new PresentateurAnnulation();
});
```

### 6. Les contrôles

```php
// tests/architecture/equivalence.php
$pa = new PresentateurAnnulation();
$v->reponseEgale(200, ['statut' => 'succes', 'message' => 'facture annulee'],
    $pa->annulee('F-1'), 'POST /annuler-facture succes');
$v->reponseEgale(409, ['statut' => 'erreur', 'message' => 'facture deja annulee'],
    $pa->traduire(ErreurFacturation::factureDejaAnnulee('F-1')), 'deja annulee');
```

**Le présentateur se teste sans rien câbler.** C'est pour cela qu'il n'a aucune
dépendance.

---

## 10.3 Ajouter un dépôt

**1. Le contrat, dans le domaine** — nommé selon le besoin, pas selon SQL :

```php
interface DepotRelanceInterface extends DepotInterface
{
    public function enRetardDepuis(int $jours): array;
    public function marquerRelancee(string $numero): bool;
}
```

**2. L'implémentation, dans l'infrastructure** :

```php
final class DepotRelancePdo extends DepotPdo implements DepotRelanceInterface
{
    public function enRetardDepuis(int $jours): array
    {
        return array_map([$this, 'hydrater'], $this->lignes(
            'SELECT … FROM factures WHERE statut = :statut'
            . ' AND date_limite < DATE_SUB(NOW(), INTERVAL :jours DAY)',
            [':statut' => Facture::EN_ATTENTE, ':jours' => $jours]
        ));
    }
}
```

**3. Le câblage, mémoïsé** :

```php
private function relances(): DepotRelanceInterface
{
    return $this->partage('relances', function () {
        return new DepotRelancePdo($this->connexion());
    });
}
```

**4. Le double, dans `tests/architecture/doubles.php`** — et relisez
l'implémentation réelle avant de l'écrire.

---

## 10.4 Ajouter un type d'erreur

```php
// 1. La constante et la fabrique, dans votre classe d'erreur
const QUOTA_DEPASSE = 'quota_depasse';

public static function quotaDepasse(int $limite): self
{
    return new self(self::QUOTA_DEPASSE, 'limite ' . $limite);
}

// 2. La traduction, dans CHAQUE présentateur concerné
case ErreurFacturation::QUOTA_DEPASSE:
    return $this->echec(429, 'erreur', 'quota depasse');

// 3. Le contrôle
$v->reponseEgale(429, ['statut' => 'erreur', 'message' => 'quota depasse'],
    $p->traduire(ErreurFacturation::quotaDepasse(100)), 'quota');
```

> **⚠ Un type non traduit tombe dans `default` et devient un 500.** Techniquement
> correct, mais c'est un incident annoncé au consommateur là où il n'y en a pas.
> Si vous ajoutez un type, ajoutez sa traduction dans le même mouvement.

---

## 10.5 Changer la durée de vie des jetons

Une seule valeur, dans le câblage :

```php
new Connecter($this->utilisateurs(), $this->jetons(), $this->journal(), 1800);
```

Rien d'autre à toucher : `JetonJwt` ajoute `exp` à l'émission, et le vérifie à
chaque appel.

---

## 10.6 Remplacer une implémentation

C'est l'intérêt des contrats. Pour passer de JWT à des jetons opaques en base :

```php
// 1. Écrire l'implémentation
final class JetonOpaque implements JetonInterface { /* … */ }

// 2. Changer UNE ligne dans la fabrique
private function jetons(): JetonInterface
{
    return $this->partage('jetons', function () {
        return new JetonOpaque($this->connexion());
    });
}
```

**Aucun cas d'usage, aucun contrôleur, aucun présentateur ne change.** Ils ne
connaissaient que `JetonInterface`.

---

## 10.7 Reprendre un projet existant

L'ordre compte. Chaque étape prépare la suivante.

**1. Poser le filet AVANT de toucher au code.**

```bash
cp -r phpCleanCode/outils/caracterisation mon-projet/tests/
cd mon-projet/tests/caracterisation
cp config.sample.php config.php   # renseigner
cp cases.exemple.php  cases.php   # réécrire pour vos endpoints
php run.php record                # la référence : le code AVANT
```

Voir [`08-outils.md`](08-outils.md) § 8.4.

**2. Vérifier la version cible.**

```bash
outils/lint.bat C:\wamp64\bin\php\php7.0.33\php.exe src App\MonProjet 7.0 vendor\autoload.php
```

Faites-le tôt : découvrir en fin de refactoring que la production refuse une
construction que votre poste accepte coûte cher.

**3. Extraire le domaine, une tranche à la fois.** Un endpoint, pas sept.
Après chaque tranche : `php run.php verify`. Doit rester vert.

**4. Extraire les présentateurs.** C'est l'étape qui rend le contrat visible :
d'un coup, les codes HTTP et les libellés sont dans six fichiers au lieu d'être
dispersés partout.

**5. Écrire la suite d'équivalence** à partir des présentateurs extraits. Elle
remplacera progressivement la caractérisation dans le travail quotidien — mille
fois plus rapide, et exécutable sans serveur.

**6. Garder la caractérisation** pour les bascules. Elle seule interroge le vrai
serveur avec les vraies données.

> **⚠** Ne corrigez pas les bizarreries en chemin. Une faute de frappe dans un
> message, une majuscule inattendue, un endpoint qui rend un tableau nu : **c'est
> du contrat**. Notez-les, arbitrez-les avec les consommateurs, corrigez-les
> plus tard et délibérément.

---

## 10.8 Vérifier l'architecture mécaniquement

Trois commandes qui répondent à trois questions :

```bash
# Le domaine dépend-il de l'infrastructure ?  (doit être vide)
grep -rn "use .*\\\\Infrastructure\\\\" src/Domain src/Application

# Quelqu'un instancie-t-il hors de la fabrique ?  (doit être vide)
grep -rn "new PDO\|new .*Pdo(" src/ --include="*.php" | grep -v Fabrique

# Une classe écrit-elle sur la sortie ?  (seul ReponseHttp doit sortir)
grep -rn "^\s*echo\|print(" src/ --include="*.php"
```

Ajoutez-les à votre suite si vous voulez qu'elles soient tenues dans le temps.
