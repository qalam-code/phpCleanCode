# phpCleanCode

Socle Clean Architecture pour API PHP : la plomberie qui se repete d'un projet
a l'autre — HTTP, persistance, erreurs, authentification — plus le harnais de
tests qui va avec.

**PHP 7.0 minimum. Aucune dépendance tierce.** Seules les extensions `json`
et `pdo` sont requises. Composer installe le squelette et le framework.

La compatibilité PHP 7.0 est conservée pour les anciens déploiements ; cette
branche n'est plus prise en charge par l'équipe PHP. Pour un nouveau
déploiement, choisissez une version de PHP encore maintenue.

Ce qui n'est **pas** ici : votre domaine et vos cas d'usage. Ils sont propres a
chaque projet ; les mutualiser reviendrait a mutualiser votre metier.

---

## Philosophie

`phpCleanCode` est un socle **léger, orienté Clean Architecture et pédagogique**. Il ne cherche pas à reproduire un framework full-stack comme Laravel ou Symfony : il rend visibles les responsabilités, les dépendances et le trajet d'une requête afin que l'utilisateur puisse comprendre le système et le faire évoluer.

Ses choix privilégient les frontières explicites : les couches internes dépendent de contrats, les adaptateurs sont câblés dans une fabrique, et les exemples montrent où placer le code propre à l'application. Cette approche implique parfois plus de câblage manuel et moins de magie ; c'est un compromis assumé pour rendre l'architecture lisible.

La documentation suit la même intention : elle explique non seulement **comment** utiliser une classe, mais aussi **pourquoi** elle existe, quelle règle elle protège et où se situe sa responsabilité. Commencez par [`docs/01-principes.md`](docs/01-principes.md), puis suivez le [guide de lecture](docs/README.md).

---

## Les deux dossiers

| Dossier      | Role                                                                 |
|--------------|----------------------------------------------------------------------|
| `src/`       | La bibliotheque, espace de noms `PhpCleanCode\`                       |
| `squelette/` | Un projet complet et fonctionnel a copier pour demarrer               |
| `outils/`    | Auto-test, controle de compatibilite, harnais de caracterisation      |
| `docs/`      | Documentation detaillee : chaque couche, chaque fichier, chaque piege |

Ce fichier est la presentation en dix minutes. **`docs/` en est la version
longue** — celle qu'on ouvre quand on se demande *pourquoi* une classe est
ecrite ainsi, ou *ou* poser le code qu'on est en train d'ecrire. Commencez par
[`docs/README.md`](docs/README.md), qui en donne le sommaire.

Une version imprimable, `docs/Documentation_phpCleanCode.docx`, est generee a
partir de ces memes fichiers par `docs/generer_doc.py`. Les `.md` sont la
reference : ne corrigez jamais le `.docx` a la main.

---

## Demarrer un projet

```
composer config --global repositories.qalam-skeleton vcs https://github.com/qalam-code/php-clean-code-skeleton
composer create-project qalam-code/php-clean-code-skeleton mon-projet
```

Cette commande prend le squelette public sur GitHub ; son manifeste récupère le
framework depuis son dépôt GitHub. Après enregistrement du squelette sur
Packagist, la forme courte devient disponible :

```
composer create-project qalam-code/php-clean-code-skeleton mon-projet
```

Pour travailler depuis une copie locale du dépôt :

```
cp -r squelette /chemin/vers/mon-projet
cd /chemin/vers/mon-projet
cp .env.example .env
```

Puis, dans la copie :

1. Renommez l'espace de noms `App\Exemple\` en celui de votre projet
   (`src/`, `autoload.php`, `composer.json`).
2. Configurez `.env` (copie automatique de `.env.example`). En production,
   definissez les variables dans le vhost. Ajustez aussi `RewriteBase` dans
   `public/.htaccess`.
3. Remplacez le domaine d'exemple (facture, utilisateur) par le votre.
4. Declarez les chemins et methodes dans `routes/api.php` ; cablez les services dans `src/Fabrique.php`.

Verifiez que tout repond avant d'ecrire une ligne :

```
php tests/architecture/equivalence.php
```

---

## La carte

```
Requete  ->  Aiguillage  ->  Controleur  ->  Cas d'usage  ->  Depot  ->  base
                 |               |                |
                 |               +--------------> Presentateur  ->  ReponseHttp
                 +-- attrape tout ce qui remonte -^
```

**La regle de dependance, et il n'y en a qu'une : les fleches vont toujours
vers l'interieur.** L'infrastructure depend des contrats Domain et Application ;
ces couches interieures ignorent l'infrastructure. Les contrats metier vivent
dans `Domain/Contrat/`, les ports requis par les cas d'usage dans
`Application/Port/`.

| Couche            | Connait                       | Ne connait pas                  |
|-------------------|-------------------------------|---------------------------------|
| `Domain`          | entites et contrats metier    | Application, HTTP, SQL, JSON    |
| `Application`     | `Domain`, ses ports           | HTTP, SQL, JSON                 |
| `Infrastructure`  | contrats Domain et Application| HTTP                            |
| `Presentation`    | `Domain`, `Application`       | SQL                             |

---

## Ce que fournit la bibliotheque

### Domaine

- **`ErreurMetier`** — une seule exception, qualifiee par un *type*. Le domaine
  dit ce qui ne va pas ; le presentateur traduit en code HTTP. Etendez-la pour
  ajouter vos types.
- **`Entite\Identite`** — l'appelant authentifie. Ne peut venir que d'un jeton
  verifie, jamais d'un champ de la requete.
- **`Domain\Contrat\DepotInterface`** — marqueur pour les contrats de depots
  propres au domaine.

### Ports d'application

- **`Application\Port\`** — `JetonInterface`, `AuthentificationInterface`,
  `JournalInterface`, `SurveillanceInterface`, `ResolveurActeurInterface`.

### Infrastructure

- **`FabriqueConnexion`** — PDO ouverte au dernier moment, avec `USE base`
  explicite en filet de securite.
- **`DepotPdo`** — socle des depots : preparer, executer, ramener. Pas de CRUD
  generique, volontairement.
- **`JetonJwt`** — JWT HS256 sans dependance. Algorithme impose, comparaison en
  temps constant, `exp` obligatoire.
- **`JournalPdo`** — trace en base qui rend `false` au lieu de lever.
- **`SurveillanceTimeout`** — garde-temps avec marge.
- **`AuthentificationDifferee`** — retarde la construction de l'authentification.

### HTTP et presentation

- **`Requete`** — les superglobales lues une seule fois, a un seul endroit.
- **`Routeur`** — table des routes ; **chaque route porte son presentateur**.
- **`Aiguillage`** — point d'entree unique ; aucune exception n'en sort.
- **`ReponseHttp`** — code, corps, en-tetes. Seul objet qui ecrit sur la sortie.
- **`Presentateur\PresentateurAbstrait`** — le contrat de l'API vit ici.
- **`Presentateur\PresentateurCommun`** — traductions des types de la biblio.

### Composition

- **`Fabrique` et `Conteneur`** — câblage explicite des services, construction
  différée et instances partagées pendant une requête.

### Tests

- **`Test\Verificateur`** — `egal`, `vrai`, `leve`, `reponseEgale`, `bilan`.
- **`Test\JournalFactice`**, **`SurveillanceFactice`**, **`JetonFactice`**,
  **`AuthentificationFactice`**.

---

## Commandes

```bash
# Auto-test de la bibliotheque (55 controles, 57 avec PDO SQLite)
php outils/verification.php

# Regenerer la documentation Word depuis les fichiers Markdown
python docs/generer_doc.py docs/ docs/Documentation_phpCleanCode.docx

# Suite d'equivalence du squelette (53 controles)
php squelette/tests/architecture/equivalence.php

# Syntaxe + liaison des classes, sous une version precise
outils/lint.sh php7.0 src PhpCleanCode 7.0
outils\lint.bat C:\wamp64\bin\php\php7.0.33\php.exe src PhpCleanCode 7.0

# Sur un projet bati avec le socle
outils\lint.bat C:\wamp64\bin\php\php7.0.33\php.exe ..\mon-projet\src App\MonProjet 7.0 ..\mon-projet\vendor\autoload.php
```

`lint` fait deux passes. **La seconde est celle qui compte** : `php -l` ne
verifie que la syntaxe, il ne declare pas les classes et ne voit donc aucune
erreur de liaison — visibilite, signatures, interfaces.

---

## Le harnais de caracterisation

`outils/caracterisation/` sert a un cas precis : **refactorer un code existant
sans rien casser**.

Le principe est celui de Michael Feathers : avant de toucher a du code dont
personne ne connait plus le comportement exact, on l'interroge tel qu'il est et
on enregistre ce qu'il repond — y compris ses bizarreries. Ces reponses
deviennent la reference. Le refactoring est reussi quand le nouveau code rend
exactement les memes.

Cela ne teste pas ce que le code *devrait* faire. Cela verifie qu'il fait
toujours *ce qu'il faisait*. Les deux questions sont differentes, et la seconde
est la seule qui protege vos consommateurs.

```
cp outils/caracterisation/config.sample.php outils/caracterisation/config.php
cp outils/caracterisation/cases.exemple.php outils/caracterisation/cases.php
php outils/caracterisation/run.php
```

`config.php` contient des identifiants : **ne le versionnez pas.**

---

## Les pieges que ce socle desamorce

Chacun a ete rencontre en production. Les commentaires du code les racontent
la ou ils se posent.

1. **Le constructeur d'exception prive.** `Exception::__construct` est publique ;
   PHP 7.0 refuse qu'une classe fille reduise cette visibilite, et le refus est
   *fatal au chargement*. PHP 8 l'accepte. Une suite de tests verte sous PHP 8
   ne prouve donc rien pour une production en 7.x : `outils/compat.php` est la
   pour ca.

2. **La connexion ouverte trop tot.** Si construire l'authentification ouvre la
   base, une base injoignable fait repondre « erreur interne » a une requete
   sans jeton, au lieu de « token introuvable ». Le diagnostic rendu au
   consommateur devient faux. D'ou `AuthentificationDifferee`.

3. **Le presentateur unique.** Une panne de cablage rendue par le format d'un
   autre endpoint : une base injoignable repondait « identifiants invalides »
   a chaque appel. D'ou un presentateur par route.

4. **Le double qui ment.** Un double de journal qui levait la ou le depot reel
   n'a jamais leve : la branche `catch` correspondante etait testee, verte, et
   morte en production. Relisez l'implementation avant d'ecrire un double.

5. **Le message technique qui fuit.** `SQLSTATE[HY000] [2002] Connection
   refused` livre le moteur, l'hote et parfois l'utilisateur. Un presentateur
   traduit, il ne relaie pas.

6. **Les tables non prefixees.** Une seule requete qui nomme une table sans
   prefixer sa base, et une copie de developpement lit puis ecrit en
   production. D'ou le `USE base` explicite de `FabriqueConnexion`.

7. **L'ordre des cles JSON.** Il fait partie du contrat des que des tiers
   consomment l'API. `Verificateur::reponseEgale()` le surveille.

---

## Conventions

- Un type par fichier, PSR-4, nommage francais — cohérent avec le reste.
- Tout ce qui est instancie l'est dans `Fabrique`. Ailleurs, on recoit.
- Une classe qui fait `echo` est une classe qu'on ne pourra plus tester.
- Un commentaire dit **pourquoi**, pas **quoi**. Le code dit deja quoi.

