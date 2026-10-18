# 1. Principes

## L'intention du socle

`phpCleanCode` est conçu comme un socle léger **orienté Clean Architecture et pédagogique**. Son objectif n'est pas de cacher l'architecture derrière des conventions implicites, mais de rendre visibles les responsabilités et les dépendances pour que l'utilisateur puisse les comprendre.

Les exemples servent de supports d'apprentissage : le domaine et les cas d'usage appartiennent à l'application ; le socle fournit les mécanismes répétitifs et montre comment les brancher. Le câblage explicite dans `Fabrique` est volontaire : il permet de voir d'où viennent les dépendances et où remplacer un adaptateur.

Cette lisibilité a un coût assumé : il y a parfois plus de classes et de câblage manuel qu'avec un framework full-stack riche en conventions. L'objectif est de choisir l'abstraction utile, pas d'ajouter une couche pour chaque principe. Les principes SOLID guident les décisions ; ils ne remplacent pas le jugement sur le besoin réel.

La suite de ce chapitre explique les règles concrètes qui matérialisent cette intention.

## 1.1 La règle de dépendance

Tout le reste en découle, et il n'y en a qu'une :

> **Les dépendances pointent toujours vers l'intérieur.**
> L'infrastructure connaît le domaine ; le domaine ignore l'infrastructure.

Concrètement, en PHP, cela se lit dans les `use` :

```php
// Domain/ ou Application/
use PhpCleanCode\Infrastructure\JetonJwt;   // ← INTERDIT
use PhpCleanCode\Application\Port\JetonInterface;   // ← correct
```

Le contrat appartient à la couche intérieure qui en a besoin : `Domain/Contrat/`
pour un besoin métier, `Application/Port/` pour un cas d'usage. Cette règle se
vérifie mécaniquement :

```bash
grep -rn "use .*\\\\Infrastructure\\\\" src/Domain src/Application
```

Zéro résultat, ou l'architecture est déjà entamée.

## 1.2 Les quatre couches

```
             ┌─────────────────────────────────────────┐
             │            Presentation                 │  HTTP, JSON
             │   Controleur · Presentateur · Reponse   │
             └────────────────┬────────────────────────┘
                              │
             ┌────────────────▼────────────────────────┐
             │            Application                  │  cas d'usage
             │   « payer une facture », « se connecter »│
             └────────────────┬────────────────────────┘
                              │
             ┌────────────────▼────────────────────────┐
             │              Domain                     │  le métier
             │   Entités · Contrats · ErreurMetier     │  ne connaît RIEN
             └────────────────▲────────────────────────┘
                              │ implémente les contrats
             ┌────────────────┴────────────────────────┐
             │           Infrastructure                │  SQL, réseau, temps
             │   Dépôts · JWT · Journal · Connexion    │
             └─────────────────────────────────────────┘
```

Remarquez le sens de la flèche du bas : **elle remonte**. L'infrastructure
dépend du domaine, jamais l'inverse. C'est l'inversion de dépendance, et c'est
ce qui rend le domaine testable sans base de données.

| Couche | Connaît | Ne connaît pas |
|---|---|---|
| `Domain` | ses entités et contrats métier | Application, HTTP, SQL, JSON |
| `Application` | `Domain`, ses ports | HTTP, SQL, JSON |
| `Infrastructure` | contrats Domain et Application | HTTP |
| `Presentation` | `Domain`, `Application` | SQL |

## 1.3 Où va ce que j'écris ?

Une question suffit :

> *Est-ce que ce code survivrait si l'on remplaçait MySQL par des fichiers, et
> HTTP par une ligne de commande ?*

- **Oui** → `Domain` ou `Application`.
- **Non** → `Infrastructure` ou `Presentation`.

Puis, plus finement :

| Vous écrivez… | …dans |
|---|---|
| une notion métier et ses invariants | `Domain/Entite/` |
| un contrat propre aux règles métier | `Domain/Contrat/` |
| un service externe requis par un cas d'usage | `Application/Port/` |
| vos types d'erreur | `Domain/ErreurXxx.php` |
| une opération complète (« payer ») | `Application/` |
| du SQL, un appel réseau, une lecture de fichier | `Infrastructure/` |
| l'extraction des paramètres HTTP | `Presentation/Controleur/` |
| les codes HTTP et le format des réponses | `Presentation/Presentateur/` |
| les chemins et méthodes HTTP | `routes/api.php` dans le projet |
| le câblage des contrôleurs et présentateurs | `Fabrique.php` |

## 1.4 Le trajet d'une requête

```
  navigateur
     │  POST /facture  {"numero":"F-2026-001"}
     ▼
  public/index.php ──── Requete::depuisGlobales()
     │                  (seul endroit qui lit les superglobales)
     ▼
  Fabrique::routeur() ── câble tout, n'ouvre rien
     │
     ▼
  Aiguillage::servir() ── résout la route, attrape TOUT ce qui remonte
     │
     ▼
  Controleur ─────────── extrait les paramètres, appelle, présente
     │
     ▼
  Cas d'usage ────────── la règle métier. Rend une donnée, ou lève.
     │
     ├──► Depot ───────► base de données
     │
     ▼
  Presentateur ───────── traduit en code HTTP + corps JSON
     │
     ▼
  ReponseHttp::envoyer() ── seul endroit qui écrit sur la sortie
```

Deux points méritent d'être soulignés, parce qu'ils sont ce qui rend l'ensemble
testable :

1. **Les superglobales sont lues une seule fois**, dans
   `Requete::depuisGlobales()`. Partout ailleurs, on reçoit une `Requete`. Un
   test en construit une avec les valeurs qu'il veut.
2. **Un seul objet écrit sur la sortie** : `ReponseHttp::envoyer()`. Tout le
   reste *retourne*. Une classe qui fait `echo` est une classe qu'on ne pourra
   plus tester.

## 1.5 Ce que le socle apporte, et ce qu'il n'apporte pas

**Il apporte la plomberie** — celle qui se répète à l'identique d'un projet à
l'autre : lire une requête, router, authentifier, parler à PDO, signer un
jeton, traduire une erreur en code HTTP, tracer, tester.

**Il n'apporte ni domaine ni cas d'usage**, et ce n'est pas un oubli. En Clean
Architecture ils sont par nature propres à chaque projet : les mutualiser
reviendrait à mutualiser le métier. Le squelette en fournit un exemple complet,
destiné à être remplacé, pas étendu.

## 1.6 Conventions d'écriture

- **Un type par fichier**, PSR-4, nommage français — cohérent avec le reste.
- **Tout ce qui est instancié l'est dans `Fabrique`.** Ailleurs, on reçoit.
- **Un commentaire dit *pourquoi*, pas *quoi*.** Le code dit déjà quoi.
- **Les invariants se défendent dans le constructeur.** Mieux vaut échouer à
  construire un objet que le promener à moitié rempli dans toute l'application.
- **Pas de `final` sur ce qui est fait pour être étendu** (`ErreurMetier`,
  `PresentateurAbstrait`, `PresentateurCommun`, `DepotPdo`, `Fabrique`) ;
  `final` partout ailleurs. Le mot-clé documente l'intention.

