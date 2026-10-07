# Documentation de phpCleanCode

Documentation détaillée du socle : chaque couche, chaque fichier, chaque
décision de conception.

Cette documentation porte la même intention que le code : un socle léger,
orienté Clean Architecture et pédagogique. Elle expose les choix et leurs
compromis afin que le lecteur puisse comprendre l'architecture, pas seulement
copier des recettes.

Le `README.md` à la racine est une présentation en dix minutes. Ceci en est la
version longue — celle qu'on ouvre quand on se demande *pourquoi* une classe est
écrite ainsi, ou *où* poser le code qu'on est en train d'écrire.

---

## Sommaire

| | Fichier | Contenu |
|---|---|---|
| 1 | [`01-principes.md`](01-principes.md) | La règle de dépendance, les quatre couches, comment décider où va un fichier |
| 2 | [`02-domaine.md`](02-domaine.md) | `ErreurMetier`, `Identite`, contrat métier et ports d'application |
| 3 | [`03-infrastructure.md`](03-infrastructure.md) | `FabriqueConnexion`, `DepotPdo`, `JetonJwt`, `JournalPdo`, `SurveillanceTimeout`, `AuthentificationDifferee` |
| 4 | [`04-http.md`](04-http.md) | `Requete`, `Routeur`, `Aiguillage` |
| 5 | [`05-presentation.md`](05-presentation.md) | `ReponseHttp`, `Authentificateur`, `PresentateurAbstrait`, `PresentateurCommun` |
| 6 | [`06-composition.md`](06-composition.md) | `Fabrique` et `Conteneur`, la racine de composition |
| 7 | [`07-tests.md`](07-tests.md) | `Verificateur` et les quatre doubles |
| 8 | [`08-outils.md`](08-outils.md) | `verification.php`, `compat.php`, `lint`, harnais de caractérisation |
| 9 | [`09-squelette.md`](09-squelette.md) | Le projet d'exemple, fichier par fichier |
| 10 | [`10-recettes.md`](10-recettes.md) | Ajouter un endpoint, un dépôt, un type d'erreur ; migrer un projet existant |
| 11 | [`11-pieges.md`](11-pieges.md) | Les sept pièges, en détail et avec leur histoire |
| 12 | [`12-vues-web.md`](12-vues-web.md) | Préparer l'ajout de réponses HTML et de vues sans mêler le domaine au rendu |

---

## Par où commencer

**Vous découvrez le socle** → `01-principes.md`, puis `09-squelette.md`. Les
deux ensemble donnent la carte et un exemple qui tourne.

**Vous démarrez un projet** → `10-recettes.md`, recette n° 1.

**Vous reprenez un code existant** → `11-pieges.md` d'abord, puis
`08-outils.md`, section caractérisation. Dans cet ordre : le harnais ne sert à
rien si l'on n'a pas compris ce qu'il doit figer.

**Vous cherchez une signature** → la fiche de la classe, dans le chapitre de sa
couche. Chaque fiche donne la signature exacte, le rôle, le pourquoi, et ce
qu'il ne faut pas faire.

---

## Conventions de lecture

Chaque classe est présentée sur le même moule :

> **Signature** — la déclaration exacte, telle qu'elle est dans le code.
> **Rôle** — une phrase.
> **Pourquoi** — la décision de conception, et ce qu'elle évite.
> **Utilisation** — un exemple court.
> **Pièges** — ce qui casse, et comment.

Les encadrés **⚠** signalent un point où une erreur ne se voit pas tout de
suite : elle passe les tests, et se manifeste en production.

---

## Le document Word

`Documentation_phpCleanCode.docx` est **généré** à partir des fichiers ci-dessus
par `generer_doc.py` :

```bash
python docs/generer_doc.py docs/ Documentation_phpCleanCode.docx
```

> **Les fichiers Markdown sont la référence.** Ils vivent à côté du code et se
> mettent à jour avec lui. Ne corrigez jamais le `.docx` à la main : corrigez le
> `.md` et relancez le script, sinon les deux divergent en une semaine.

Dépendance : `python-docx` (`pip install python-docx`). Le sommaire du document
est un champ Word : à l'ouverture, clic droit dessus puis « Mettre à jour les
champs ».

---

## Versions

- Socle : **PHP 7.0 minimum**, extensions `json` et `pdo`. Aucune dépendance.
- Outils (`compat.php`, harnais de caractérisation) : écrits en **PHP 7.0
  strict**, pour pouvoir tourner sur la production la plus ancienne.
- Cette documentation décrit l'état du **27/09/2026** : 28 types dans `src/`,
  55 contrôles d'auto-test (57 avec PDO SQLite), 53 contrôles d'équivalence
  sur le squelette.

