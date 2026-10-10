<?php
declare(strict_types=1);

namespace PhpCleanCode\Http;

/**
 * La requete entrante, lue une fois pour toutes.
 *
 * Les superglobales sont lues dans depuisGlobales() et nulle part ailleurs.
 * C'est ce qui rend tout le reste testable : un test construit une Requete
 * avec les valeurs qu'il veut, sans toucher a $_POST ni simuler php://input.
 *
 * Les donnees sont volontairement rendues BRUTES, sans echappement. Echapper
 * a l'entree corrompt ce qu'on enregistre et ne protege de rien : c'est a la
 * sortie -- requete preparee pour SQL, json_encode pour la reponse -- que
 * l'echappement a un sens, parce que la destination est alors connue.
 *
 * @package PhpCleanCode\Http
 */
final class Requete
{
    private $methode;
    private $chemin;
    private $corps;
    private $requeteUrl;
    private $entetes;
    private $corpsInvalide;
    private $fichiers;

    public function __construct(
        string $methode,
        string $chemin,
        array $corps = [],
        array $requeteUrl = [],
        array $entetes = [],
        bool $corpsInvalide = false,
        array $fichiers = []
    ) {
        $this->methode    = strtoupper($methode);
        $this->chemin     = $chemin;
        $this->corps      = $corps;
        $this->requeteUrl = $requeteUrl;
        $this->entetes    = array_change_key_case($entetes, CASE_LOWER);
        $this->corpsInvalide = $corpsInvalide;
        $this->fichiers = $fichiers;
    }

    public static function depuisGlobales(): self
    {
        $brut  = (string) file_get_contents('php://input');
        $corps = [];
        $corpsInvalide = false;
        if ($brut !== '') {
            $decode = json_decode($brut);
            if (json_last_error() === JSON_ERROR_NONE && is_object($decode)) {
                $corps = (array) $decode;
            } elseif ($_POST !== []) {
                // Un formulaire classique peut aussi laisser des octets dans
                // php://input ; $_POST reste alors la source de ses champs.
                $corps = $_POST;
            } else {
                $corpsInvalide = true;
            }
        }
        // Un formulaire classique vide n'arrive pas par php://input.
        if ($brut === '' && $_POST !== []) {
            $corps = $_POST;
        }

        $chemin = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $chemin,
            $corps,
            $_GET,
            self::entetesDuServeur(),
            $corpsInvalide,
            self::fichiersDuServeur($_FILES)
        );
    }

    public function methode(): string
    {
        return $this->methode;
    }

    public function chemin(): string
    {
        return $this->chemin;
    }

    /** @return array<string,mixed> */
    public function corps(): array
    {
        return $this->corps;
    }

    /** Le flux brut contenait un JSON invalide ou qui n'etait pas un objet. */
    public function corpsInvalide(): bool
    {
        return $this->corpsInvalide;
    }

    /** Retourne le fichier televerse associe a un champ, ou null s'il est absent. */
    public function fichier(string $cle)
    {
        return $this->fichiers[$cle] ?? null;
    }

    /** @return array<string,mixed> FichiersTeleverse ou tableaux imbriques pour les champs multiples. */
    public function fichiers(): array
    {
        return $this->fichiers;
    }

    /** Convertit la structure speciale de $_FILES en objets independants de PHP. */
    private static function fichiersDuServeur(array $fichiers): array
    {
        $resultat = [];
        foreach ($fichiers as $cle => $fichier) {
            if (!is_array($fichier)
                || !array_key_exists('name', $fichier)
                || !array_key_exists('type', $fichier)
                || !array_key_exists('tmp_name', $fichier)
                || !array_key_exists('error', $fichier)
                || !array_key_exists('size', $fichier)
            ) {
                continue;
            }
            $resultat[$cle] = self::normaliserFichier($fichier);
        }
        return $resultat;
    }

    /** Recompose aussi les champs multiples name="fichiers[]". */
    private static function normaliserFichier(array $fichier)
    {
        if (is_array($fichier['name'])) {
            $resultat = [];
            foreach ($fichier['name'] as $cle => $nom) {
                $enfant = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $attribut) {
                    $enfant[$attribut] = isset($fichier[$attribut][$cle])
                        ? $fichier[$attribut][$cle]
                        : ($attribut === 'error' ? UPLOAD_ERR_NO_FILE : '');
                }
                $resultat[$cle] = self::normaliserFichier($enfant);
            }
            return $resultat;
        }

        return new FichierTeleverse(
            is_string($fichier['name']) ? $fichier['name'] : '',
            is_string($fichier['type']) ? $fichier['type'] : '',
            is_string($fichier['tmp_name']) ? $fichier['tmp_name'] : '',
            is_int($fichier['error']) ? $fichier['error'] : (int) $fichier['error'],
            is_int($fichier['size']) ? $fichier['size'] : (int) $fichier['size']
        );
    }

    /** @return mixed valeur du corps, puis de la query string, sinon $defaut. */
    public function parametre(string $cle, $defaut = null)
    {
        if (array_key_exists($cle, $this->corps)) {
            return $this->corps[$cle];
        }
        return $this->requeteUrl[$cle] ?? $defaut;
    }

    /**
     * Les cles absentes ET les chaines vides comptent comme manquantes : une
     * valeur vide passee par erreur n'est pas une valeur.
     *
     * @param array<int,string> $cles
     * @return array<int,string> celles qui manquent, dans l'ordre demande.
     */
    public function manquants(array $cles): array
    {
        $absents = [];
        foreach ($cles as $cle) {
            $valeur = $this->parametre($cle);
            if ($valeur === null || $valeur === '') {
                $absents[] = $cle;
            }
        }
        return $absents;
    }

    public function entete(string $nom){
        return $this->entetes[strtolower($nom)] ?? null;
    }

    /** @return array<string,string> */
    private static function entetesDuServeur(): array
    {
        $entetes = [];
        if (function_exists('getallheaders')) {
            $globales = getallheaders();
            if (is_array($globales)) {
                $entetes = $globales;
            }
        }

        // getallheaders() peut etre present tout en omettant Authorization.
        // On complete donc toujours avec $_SERVER, sans ecraser une valeur
        // deja fournie par la fonction.
        $nomsConnus = array_change_key_case($entetes, CASE_LOWER);
        foreach ($_SERVER as $cle => $valeur) {
            if (strpos((string) $cle, 'HTTP_') === 0) {
                $nom = str_replace('_', '-', substr((string) $cle, 5));
                $nomNormalise = strtolower($nom);
                if (!array_key_exists($nomNormalise, $nomsConnus)) {
                    $entetes[$nom] = (string) $valeur;
                    $nomsConnus[$nomNormalise] = true;
                }
            }
        }
        // Apache masque Authorization quand CGIPassAuth est desactive ; la
        // reecriture de .htaccess le remet ici.
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            && !array_key_exists('authorization', $nomsConnus)
        ) {
            $entetes['Authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $entetes;
    }
}

