# 1. Principes

## L'intention du socle

`phpCleanCode` est conçu comme un socle léger **orienté Clean Architecture et pédagogique**. Son objectif n'est pas de cacher l'architecture derrière des conventions implicites, mais de rendre visibles les responsabilités et les dépendances pour que l'utilisateur puisse les comprendre.

Les exemples servent de supports d'apprentissage : le domaine et les cas d'usage appartiennent à l'application; le socle fournit les mécanismes répétitifs et montre comment les brancher. Le câblage explicite dans `Fabrique` est volontaire : il permet de voir d'où viennent les dépendances et où remplacer un adaptateur.

Cette lisibilité a un coût assumé : il y a parfois plus de classes et de câblage manuel qu'avec un framework full-stack riche en conventions. L'objectif est de choisir l'abstraction utile, pas d'ajouter une couche pour chaque principe. Les principes SOLID guident les décisions; ils ne remplacent pas le jugement sur le besoin réel.

La suite de ce chapitre explique les règles concrètes qui matérialisent cette intention.

## 1.1 La r�gle de d�pendance

Tout le reste en d�coule, et il n'y en a qu'une :

> **Les d�pendances pointent toujours vers l'int�rieur.**
> L'infrastructure conna�t le domaine ; le domaine ignore l'infrastructure.

Concr�tement, en PHP, cela se lit dans les `use` :

```php
// Domain/ ou Application/
use PhpCleanCode\Infrastructure\JetonJwt;   // ? INTERDIT
use PhpCleanCode\Application\Port\JetonInterface;   // ? correct
```

Le contrat appartient � la couche int�rieure qui en a besoin : `Domain/Contrat/`
pour un besoin m�tier, `Application/Port/` pour un cas d'usage. Cette r�gle se
v�rifie m�caniquement :

```bash
grep -rn "use .*\\\\Infrastructure\\\\" src/Domain src/Application
```

Z�ro r�sultat, ou l'architecture est d�j� entam�e.

## 1.2 Les quatre couches

```
             +-----------------------------------------+
             �            Presentation                 �  HTTP, JSON
             �   Controleur � Presentateur � Reponse   �
             +-----------------------------------------+
                              �
             +----------------?------------------------+
             �            Application                  �  cas d'usage
             �   � payer une facture �, � se connecter ��
             +-----------------------------------------+
                              �
             +----------------?------------------------+
             �              Domain                     �  le m�tier
             �   Entit�s � Contrats � ErreurMetier     �  ne conna�t RIEN
             +----------------?------------------------+
                              � impl�mente les contrats
             +-----------------------------------------+
             �           Infrastructure                �  SQL, r�seau, temps
             �   D�p�ts � JWT � Journal � Connexion    �
             +-----------------------------------------+
```

Remarquez le sens de la fl�che du bas : **elle remonte**. L'infrastructure
d�pend du domaine, jamais l'inverse. C'est l'inversion de d�pendance, et c'est
ce qui rend le domaine testable sans base de donn�es.

| Couche | Conna�t | Ne conna�t pas |
|---|---|---|
| `Domain` | ses entit�s et contrats m�tier | Application, HTTP, SQL, JSON |
| `Application` | `Domain`, ses ports | HTTP, SQL, JSON |
| `Infrastructure` | contrats Domain et Application | HTTP |
| `Presentation` | `Domain`, `Application` | SQL |

## 1.3 O� va ce que j'�cris ?

Une question suffit :

> *Est-ce que ce code survivrait si l'on rempla�ait MySQL par des fichiers, et
> HTTP par une ligne de commande ?*

- **Oui** ? `Domain` ou `Application`.
- **Non** ? `Infrastructure` ou `Presentation`.

Puis, plus finement :

| Vous �crivez� | �dans |
|---|---|
| une notion m�tier et ses invariants | `Domain/Entite/` |
| un contrat propre aux r�gles m�tier | `Domain/Contrat/` |
| un service externe requis par un cas d'usage | `Application/Port/` |
| vos types d'erreur | `Domain/ErreurXxx.php` |
| une op�ration compl�te (� payer �) | `Application/` |
| du SQL, un appel r�seau, une lecture de fichier | `Infrastructure/` |
| l'extraction des param�tres HTTP | `Presentation/Controleur/` |
| les codes HTTP et le format des r�ponses | `Presentation/Presentateur/` |
| chemins et methodes HTTP | routes/api.php dans le projet |
| cablage des controleurs et presentateurs | Fabrique.php |

## 1.4 Le trajet d'une requ�te

```
  navigateur
     �  POST /facture  {"numero":"F-2026-001"}
     ?
  public/index.php ---- Requete::depuisGlobales()
     �                  (seul endroit qui lit les superglobales)
     ?
  Fabrique::routeur() -- c�ble tout, n'ouvre rien
     �
     ?
  Aiguillage::servir() -- r�sout la route, attrape TOUT ce qui remonte
     �
     ?
  Controleur ----------- extrait les param�tres, appelle, pr�sente
     �
     ?
  Cas d'usage ---------- la r�gle m�tier. Rend une donn�e, ou l�ve.
     �
     +--? Depot -------? base de donn�es
     �
     ?
  Presentateur --------- traduit en code HTTP + corps JSON
     �
     ?
  ReponseHttp::envoyer() -- seul endroit qui �crit sur la sortie
```

Deux points m�ritent d'�tre soulign�s, parce qu'ils sont ce qui rend l'ensemble
testable :

1. **Les superglobales sont lues une seule fois**, dans
   `Requete::depuisGlobales()`. Partout ailleurs, on re�oit une `Requete`. Un
   test en construit une avec les valeurs qu'il veut.
2. **Un seul objet �crit sur la sortie** : `ReponseHttp::envoyer()`. Tout le
   reste *retourne*. Une classe qui fait `echo` est une classe qu'on ne pourra
   plus tester.

## 1.5 Ce que le socle apporte, et ce qu'il n'apporte pas

**Il apporte la plomberie** � celle qui se r�p�te � l'identique d'un projet �
l'autre : lire une requ�te, router, authentifier, parler � PDO, signer un
jeton, traduire une erreur en code HTTP, tracer, tester.

**Il n'apporte ni domaine ni cas d'usage**, et ce n'est pas un oubli. En Clean
Architecture ils sont par nature propres � chaque projet : les mutualiser
reviendrait � mutualiser le m�tier. Le squelette en fournit un exemple complet,
destin� � �tre remplac�, pas �tendu.

## 1.6 Conventions d'�criture

- **Un type par fichier**, PSR-4, nommage fran�ais � coh�rent avec le reste.
- **Tout ce qui est instanci� l'est dans `Fabrique`.** Ailleurs, on re�oit.
- **Un commentaire dit *pourquoi*, pas *quoi*.** Le code dit d�j� quoi.
- **Les invariants se d�fendent dans le constructeur.** Mieux vaut �chouer �
  construire un objet que le promener � moiti� rempli dans toute l'application.
- **Pas de `final` sur ce qui est fait pour �tre �tendu** (`ErreurMetier`,
  `PresentateurAbstrait`, `PresentateurCommun`, `DepotPdo`, `Fabrique`) ;
  `final` partout ailleurs. Le mot-cl� documente l'intention.

