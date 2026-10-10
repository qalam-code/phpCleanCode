<?php
declare(strict_types=1);

/**
 * Auto-test de la bibliotheque.
 *
 *     php outils/verification.php
 *
 * A LANCER AVEC LE PHP DE VOTRE PRODUCTION, et pas seulement avec celui de
 * votre poste. Une bibliotheque verte sous PHP 8 et fatale sous PHP 7.0, cela
 * s'est deja vu -- c'est meme la raison d'etre du dernier controle de ce
 * fichier.
 *
 * Ce que ce fichier eprouve : la signature des jetons, la chaine
 * d'authentification, la paresse de la connexion, le contrat de reponse, le
 * routage, et la declarabilite des classes.
 */

require dirname(__DIR__) . '/autoload.php';

use PhpCleanCode\Application\Port\ResolveurActeurInterface;
use PhpCleanCode\Conteneur;
use PhpCleanCode\Domain\Entite\Identite;
use PhpCleanCode\Domain\ErreurMetier;
use PhpCleanCode\Http\Aiguillage;
use PhpCleanCode\Http\Requete;
use PhpCleanCode\Http\Routeur;
use PhpCleanCode\Infrastructure\AuthentificationDifferee;
use PhpCleanCode\Infrastructure\FabriqueConnexion;
use PhpCleanCode\Infrastructure\JetonJwt;
use PhpCleanCode\Infrastructure\JournalPdo;
use PhpCleanCode\Infrastructure\SurveillanceTimeout;
use PhpCleanCode\Presentation\Authentificateur;
use PhpCleanCode\Presentation\Presentateur\PresentateurCommun;
use PhpCleanCode\Presentation\ReponseHttp;
use PhpCleanCode\Test\AuthentificationFactice;
use PhpCleanCode\Test\JournalFactice;
use PhpCleanCode\Test\SurveillanceFactice;
use PhpCleanCode\Test\Verificateur;

$v = new Verificateur();
echo 'PHP ' . PHP_VERSION . PHP_EOL;

// ---------------------------------------------------------------------------
$v->section('Conteneur -- duree de vie et erreurs de configuration');

$conteneur = new Conteneur();
$constructionsPartagees = 0;
$conteneur->definir('partage', function () use (&$constructionsPartagees) {
    $constructionsPartagees++;
    return new stdClass();
});
$partage1 = $conteneur->obtenir('partage');
$partage2 = $conteneur->obtenir('partage');
$v->vrai($partage1 === $partage2, 'un service partage retourne la meme instance');
$v->egal(1, $constructionsPartagees, 'un service partage est construit une seule fois');

$constructionsSimples = 0;
$conteneur->definir('nouvelle-instance', function () use (&$constructionsSimples) {
    $constructionsSimples++;
    return new stdClass();
}, false);
$instance1 = $conteneur->obtenir('nouvelle-instance');
$instance2 = $conteneur->obtenir('nouvelle-instance');
$v->vrai($instance1 !== $instance2, 'un service non partage retourne une nouvelle instance');
$v->egal(2, $constructionsSimples, 'un service non partage est construit a chaque resolution');

$messageServiceInconnu = null;
try {
    $conteneur->obtenir('absent');
} catch (InvalidArgumentException $e) {
    $messageServiceInconnu = $e->getMessage();
}
$v->egal('Service inconnu : absent', $messageServiceInconnu, 'un service inconnu nomme son identifiant');

$messageDoublon = null;
try {
    $conteneur->definir('partage', function () { return new stdClass(); });
} catch (InvalidArgumentException $e) {
    $messageDoublon = $e->getMessage();
}
$v->egal('Service deja defini : partage', $messageDoublon, 'un doublon est refuse et nomme');

$cycle = new Conteneur();
$cycle->definir('A', function ($c) { return $c->obtenir('B'); });
$cycle->definir('B', function ($c) { return $c->obtenir('A'); });
$messageCycle = null;
try {
    $cycle->obtenir('A');
} catch (RuntimeException $e) {
    $messageCycle = $e->getMessage();
}
$v->egal('Dependance circulaire detectee : A -> B -> A', $messageCycle, 'un cycle montre le chemin complet');

// ---------------------------------------------------------------------------
$v->section('JetonJwt -- ce qui doit etre accepte');

$jwt    = new JetonJwt('secret-de-test-suffisamment-long');
$jeton  = $jwt->emettre(['sub' => 42], 3600);
$charge = $jwt->verifier($jeton);

$v->egal(42, $charge['sub'], 'la charge revient intacte');
$v->vrai(isset($charge['iat']), 'iat est ajoute');
$v->egal(3, count(explode('.', $jeton)), 'trois segments');
$v->egal(false, strpos($jeton, '=') !== false, 'base64url : pas de remplissage');

// ---------------------------------------------------------------------------
$v->section('JetonJwt -- ce qui doit etre refuse');

$v->leve(ErreurMetier::JETON_MALFORME, function () use ($jwt) {
    return $jwt->verifier('pas-un-jeton');
}, 'chaine quelconque');

$v->leve(ErreurMetier::JETON_MAL_SIGNE, function () use ($jwt, $jeton) {
    // Charge modifiee : sub passe de 42 a 1. Le classique.
    $parts    = explode('.', $jeton);
    $parts[1] = rtrim(strtr(base64_encode((string) json_encode(
        ['sub' => 1, 'exp' => time() + 3600]
    )), '+/', '-_'), '=');
    return $jwt->verifier(implode('.', $parts));
}, 'charge modifiee : signature invalide');

$v->leve(ErreurMetier::JETON_MAL_SIGNE, function () use ($jwt, $jeton) {
    // "alg":"none" : le jeton s'annonce non signe. Une bibliotheque qui lit
    // l'entete pour choisir son algorithme l'accepte. Pas celle-ci.
    $enc = function ($d) {
        return rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');
    };
    return $jwt->verifier(
        $enc(['typ' => 'JWT', 'alg' => 'none']) . '.' . $enc(['sub' => 1, 'exp' => time() + 3600]) . '.'
    );
}, 'alg:none refuse');

$v->leve(ErreurMetier::JETON_MAL_SIGNE, function () use ($jeton) {
    return (new JetonJwt('un-autre-secret'))->verifier($jeton);
}, 'signe avec un autre secret');

$v->leve(ErreurMetier::JETON_EXPIRE, function () use ($jwt) {
    return $jwt->verifier($jwt->emettre(['sub' => 42], -1));
}, 'jeton expire');

$v->leve(ErreurMetier::JETON_MALFORME, function () use ($jwt) {
    // Sans exp, un jeton serait valide pour toujours.
    $enc = function ($d) {
        return rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');
    };
    $e = $enc(['typ' => 'JWT', 'alg' => 'HS256']);
    $c = $enc(['sub' => 42]);
    $s = rtrim(strtr(base64_encode(hash_hmac('sha256', $e . '.' . $c, 'secret-de-test-suffisamment-long', true)), '+/', '-_'), '=');
    return $jwt->verifier($e . '.' . $c . '.' . $s);
}, 'jeton sans exp refuse');

$refuseSecretVide = false;
try {
    new JetonJwt('');
} catch (InvalidArgumentException $e) {
    $refuseSecretVide = true;
}
$v->vrai($refuseSecretVide, 'un secret vide fait echouer la construction');

$chargeRefusee = false;
try {
    $jwt->emettre(['texte' => "\xB1"], 3600);
} catch (InvalidArgumentException $e) {
    $chargeRefusee = true;
}
$v->vrai($chargeRefusee, 'une charge non serialisable ne produit pas un jeton vide');

// ---------------------------------------------------------------------------
$v->section('Authentificateur');

$resolveur = new class implements ResolveurActeurInterface {
    public $connu = true;
    public $appels = 0;

    public function resoudre(array $charge){
        $this->appels++;
        return $this->connu ? new Identite((int) $charge['sub'], 'test') : null;
    }
};

$avec = function ($entete) use ($jwt, $resolveur) {
    $entetes = $entete === null ? [] : ['Authorization' => $entete];
    return new Authentificateur(new Requete('GET', '/x', [], [], $entetes), $jwt, $resolveur);
};

$v->egal(42, $avec('Bearer ' . $jeton)->identifier()->id(), 'en-tete "Bearer xxx"');
$v->egal(42, $avec($jeton)->identifier()->id(), 'en-tete sans prefixe');
$v->egal(42, $avec('bearer ' . $jeton)->identifier()->id(), 'prefixe insensible a la casse');

$v->leve(ErreurMetier::JETON_ABSENT, function () use ($avec) {
    return $avec(null)->identifier();
}, 'en-tete absent');
$v->leve(ErreurMetier::JETON_ABSENT, function () use ($avec) {
    return $avec('   ')->identifier();
}, 'en-tete vide');

$resolveur->connu = false;
$v->leve(ErreurMetier::ACTEUR_INTROUVABLE, function () use ($avec, $jeton) {
    return $avec('Bearer ' . $jeton)->identifier();
}, 'compte supprime : jeton valide, acces refuse');
$resolveur->connu = true;

$resolveur->appels = 0;
$auth = $avec('Bearer ' . $jeton);
$auth->identifier();
$auth->identifier();
$v->egal(1, $resolveur->appels, 'le jeton n est verifie qu une fois par requete');

// ---------------------------------------------------------------------------
$v->section('Paresse -- ce qui ne doit PAS arriver trop tot');

$construit  = 0;
$differee   = new AuthentificationDifferee(function () use (&$construit) {
    $construit++;
    return AuthentificationFactice::acteur(7);
});
$v->egal(0, $construit, 'rien n est construit a l enveloppement');
$differee->identifier();
$v->egal(1, $construit, 'construit au premier identifier()');

// Le DSN est volontairement invalide : si la connexion s'ouvrait a la
// construction, la ligne suivante leverait.
$connexion = new FabriqueConnexion('mysql:host=255.255.255.255;dbname=neant', 'x', 'y', null);
$v->egal(false, $connexion->estOuverte(), 'aucune connexion avant le premier pdo()');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $baseInvalide = new FabriqueConnexion('sqlite::memory:', '', '', 'base_inexistante');
    $echecs = 0;
    for ($i = 0; $i < 2; $i++) {
        try {
            $baseInvalide->pdo();
        } catch (RuntimeException $e) {
            $echecs++;
        }
    }
    $v->egal([2, false], [$echecs, $baseInvalide->estOuverte()], 'une base invalide ne conserve jamais la connexion');

    $baseSilencieuse = new FabriqueConnexion(
        'sqlite::memory:',
        '',
        '',
        'base_inexistante',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]
    );
    $echecSilencieux = false;
    try {
        $baseSilencieuse->pdo();
    } catch (RuntimeException $e) {
        $echecSilencieux = true;
    }
    $v->egal([true, false], [$echecSilencieux, $baseSilencieuse->estOuverte()], 'un echec PDO silencieux ne valide pas la connexion');
}

// ---------------------------------------------------------------------------
$v->section('Surveillance');

$s = SurveillanceFactice::epuiserApres(2);
$v->vrai($s->tempsRestant(), 'etape 1 autorisee');
$v->vrai($s->tempsRestant(), 'etape 2 autorisee');
$v->egal(false, $s->tempsRestant(), 'etape 3 refusee');

// La marge doit REELLEMENT reserver du temps : limite 10, marge 9 laisse 1s.
$reelle = new SurveillanceTimeout(10.0, 9.0);
$v->vrai($reelle->tempsRestant(), 'limite 10 marge 9 : il reste du temps');
$v->egal(false, (new SurveillanceTimeout(5.0, 5.0))->tempsRestant(), 'marge egale a la limite : plus rien');

// ---------------------------------------------------------------------------
$v->section('ReponseHttp');

$r = new ReponseHttp(200, ['b' => 2, 'a' => 1]);
$v->egal('{"b":2,"a":1}', $r->json(), 'l ordre des cles est preserve');
$v->egal(
    '{"v":"' . "r\xc3\xa9gl\xc3\xa9e" . '"}',
    (new ReponseHttp(200, ['v' => "r\xc3\xa9gl\xc3\xa9e"]))->json(),
    'accents rendus tels quels, pas en \\uXXXX'
);
$jsonUtf8Invalide = (new ReponseHttp(200, ['texte' => "\xB1"]))->json();
$texteUtf8Attendu = defined('JSON_INVALID_UTF8_SUBSTITUTE')
    ? ['texte' => "\xEF\xBF\xBD"]
    : ['texte' => null];
$v->egal(
    $texteUtf8Attendu,
    json_decode($jsonUtf8Invalide, true),
    'octets UTF-8 invalides remplaces pour garder un JSON valide'
);
$corpsCirculaire = [];
$corpsCirculaire['soi'] = &$corpsCirculaire;
$jsonCirculaire = (new ReponseHttp(200, $corpsCirculaire))->json();
json_decode($jsonCirculaire, true);
$v->egal(JSON_ERROR_NONE, json_last_error(), 'une reference circulaire produit toujours du JSON valide');
$v->vrai(strpos($jsonCirculaire, 'null') !== false, 'la partie circulaire est remplacee par null');

$journal = new JournalFactice();
$journal->reussit = false;
$v->egal(false, $journal->enregistrer(1, 'x'), 'le journal rend false et NE LEVE PAS');

$v->section('JournalPdo -- identifiant SQL de la table');

$tableJournalRefusee = false;
try {
    new JournalPdo(
        new FabriqueConnexion('mysql:host=127.0.0.1;dbname=test', 'x', 'y'),
        'journal; DROP TABLE utilisateurs'
    );
} catch (InvalidArgumentException $e) {
    $tableJournalRefusee = true;
}
$v->vrai($tableJournalRefusee, 'le nom de table du journal refuse les caracteres SQL');

// ---------------------------------------------------------------------------
$v->section('Requete -- en-tetes serveur');

$serveurOriginal = $_SERVER;
$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/test',
    'HTTP_CONTENT_TYPE' => 'application/json',
    'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test',
];
try {
    $requeteServeur = Requete::depuisGlobales();
    $v->egal('Bearer test', $requeteServeur->entete('Authorization'), 'Authorization Apache recupere avec les autres en-tetes');
} finally {
    $_SERVER = $serveurOriginal;
}

$fichierSimple = new Requete('POST', '/fichier', [], [], [], false, [
    'document' => new PhpCleanCode\Http\FichierTeleverse(
        'document.pdf', 'application/pdf', '/tmp/php123', UPLOAD_ERR_OK, 42
    ),
]);
$v->egal('document.pdf', $fichierSimple->fichier('document')->nomOriginal(), 'un fichier est accessible par son nom de champ');
$v->vrai($fichierSimple->fichier('document')->estRecu(), 'UPLOAD_ERR_OK est expose sans deplacer le fichier');
$v->egal(null, $fichierSimple->fichier('absent'), 'un champ fichier absent retourne null');

$serveurOriginal = $_SERVER;
$fichiersOriginaux = $_FILES;
$_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/televersement'];
$_FILES = [
    'documents' => [
        'name' => ['a' => 'un.pdf', 'b' => 'deux.pdf'],
        'type' => ['a' => 'application/pdf', 'b' => 'application/pdf'],
        'tmp_name' => ['a' => '/tmp/php-a', 'b' => '/tmp/php-b'],
        'error' => ['a' => UPLOAD_ERR_OK, 'b' => UPLOAD_ERR_PARTIAL],
        'size' => ['a' => 10, 'b' => 5],
    ],
];
try {
    $requeteAvecFichiers = Requete::depuisGlobales();
    $v->egal('un.pdf', $requeteAvecFichiers->fichier('documents')['a']->nomOriginal(), '$_FILES est converti en objets pour les champs multiples');
    $v->egal(UPLOAD_ERR_PARTIAL, $requeteAvecFichiers->fichier('documents')['b']->erreur(), 'les erreurs PHP de televersement sont preservees');
} finally {
    $_SERVER = $serveurOriginal;
    $_FILES = $fichiersOriginaux;
}

// ---------------------------------------------------------------------------
$v->section('Architecture -- dependances vers l interieur');

$racineSource = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src';
$regles = [
    'Domain' => ['Application', 'Infrastructure', 'Presentation', 'Http'],
    'Application' => ['Infrastructure', 'Presentation', 'Http'],
    'Infrastructure' => ['Presentation', 'Http'],
];
$violations = [];

foreach ($regles as $couche => $interdites) {
    $racineCouche = $racineSource . DIRECTORY_SEPARATOR . $couche;
    if (!is_dir($racineCouche)) {
        continue;
    }

    $fichiers = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($racineCouche, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($fichiers as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        $lignes = file($fichier->getPathname(), FILE_IGNORE_NEW_LINES);
        foreach ($lignes as $numero => $ligne) {
            $import = ltrim($ligne);
            if (strpos($import, 'use PhpCleanCode\\') !== 0) {
                continue;
            }
            $parties = explode('\\', substr($import, strlen('use PhpCleanCode\\')));
            if (in_array($parties[0], $interdites, true)) {
                $relatif = substr($fichier->getPathname(), strlen($racineSource) + 1);
                $violations[] = $couche . '/' . $relatif . ':' . ($numero + 1)
                    . ' importe ' . $parties[0];
            }
        }
    }
}

$v->egal([], $violations, 'aucune dependance vers une couche exterieure interdite');

// ---------------------------------------------------------------------------
$v->section('Aiguillage');

$routeur = new Routeur();
$routeur->ajouter('ok', function () {
    return function () {
        return new ReponseHttp(200, ['statut' => 'succes']);
    };
}, function () {
    return new PresentateurCommun();
});
$routeur->ajouter('boum', function () {
    return function () {
        throw new ErreurMetier(ErreurMetier::RESSOURCE_INTROUVABLE, 'x');
    };
}, function () {
    return new PresentateurCommun();
});

$a = new Aiguillage($routeur, new PresentateurCommun());
$v->egal(200, $a->servir(new Requete('GET', '/ok'))->code(), 'route servie');
$v->egal(404, $a->servir(new Requete('GET', '/boum'))->code(), 'ErreurMetier traduite');
$v->egal(404, $a->servir(new Requete('GET', '/ailleurs'))->code(), 'chemin inconnu');
$v->egal(['ok', 'boum'], $routeur->chemins(), 'inventaire des routes');

$actionAppele = false;
$routeur->ajouter('corps-invalide', function () use (&$actionAppele) {
    return function () use (&$actionAppele) {
        $actionAppele = true;
        return new ReponseHttp(200, ['statut' => 'succes']);
    };
}, function () {
    return new PresentateurCommun();
}, ['POST']);
$reponseCorpsInvalide = $a->servir(new Requete('POST', '/corps-invalide', [], [], [], true));
$v->egal(400, $reponseCorpsInvalide->code(), 'corps JSON invalide : 400');
$v->egal(
    ['statut' => 'erreur', 'message' => 'corps de requete invalide'],
    $reponseCorpsInvalide->corps(),
    'corps JSON invalide : format public stable'
);
$v->egal(false, $actionAppele, 'corps JSON invalide : le cas d usage ne demarre pas');

$presentateurBrise = new class extends PresentateurCommun {
    public function corpsInvalide(): ReponseHttp
    {
        throw new RuntimeException('echec du presentateur');
    }

    public function panne(): ReponseHttp
    {
        throw new RuntimeException('echec du presentateur de panne');
    }
};
$routeur->ajouter('presentateur-brise', function () {
    return function () {
        return new ReponseHttp(200, ['statut' => 'succes']);
    };
}, function () use ($presentateurBrise) {
    return $presentateurBrise;
}, ['POST']);
$incidentsPresentateur = 0;
$aAvecPresentateurBrise = new Aiguillage(
    $routeur,
    new PresentateurCommun(),
    function () use (&$incidentsPresentateur) {
        $incidentsPresentateur++;
    }
);
$reponsePresentateurBrise = $aAvecPresentateurBrise->servir(
    new Requete('POST', '/presentateur-brise', [], [], [], true)
);
$v->egal(500, $reponsePresentateurBrise->code(), 'presentateur en echec : reponse sure 500');
$v->egal(2, $incidentsPresentateur, 'les echecs des deux presentateurs sont journalises');

// ---------------------------------------------------------------------------
$v->section('Declarabilite -- le piege qui a coute le plus cher');

/*
 * PHP 7.0 refuse qu'une methode redefinie reduise sa visibilite, y compris
 * pour un constructeur. Exception::__construct etant publique, une exception
 * dont le constructeur est prive ou protege ne se DECLARE PAS -- erreur
 * fatale au chargement, sur tous les endpoints a la fois.
 *
 * PHP 8 a relache cette regle. Le controle ci-dessous applique donc la regle
 * stricte quelle que soit la version qui l'execute : c'est le seul moyen de
 * s'en premunir depuis un poste plus recent que la production.
 */
foreach (['PhpCleanCode\Domain\ErreurMetier'] as $classe) {
    $reflexion   = new ReflectionClass($classe);
    $constructeur = $reflexion->getConstructor();
    $v->vrai(
        $constructeur !== null && $constructeur->isPublic(),
        $classe . '::__construct est publique (obligatoire des PHP 7.0)'
    );
}

$v->egal(
    'parametre manquant : numero',
    ErreurMetier::parametreManquant('numero')->getMessage(),
    'les fabriques nommees composent le message'
);
$v->vrai(
    ErreurMetier::jetonExpire()->estDeType(ErreurMetier::JETON_EXPIRE),
    'estDeType repond juste'
);

exit($v->bilan());

