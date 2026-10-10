<?php
declare(strict_types=1);

namespace PhpCleanCode\Http;

use InvalidArgumentException;

/**
 * Metadonnees d'un fichier recu par PHP, sans faire confiance au nom ni au type declares par le navigateur.
 * Le deplacement et le stockage du fichier restent a la charge d'un adaptateur de l'application.
 */
final class FichierTeleverse
{
    private $nomOriginal;
    private $typeDeclare;
    private $cheminTemporaire;
    private $erreur;
    private $taille;

    public function __construct(string $nomOriginal, string $typeDeclare, string $cheminTemporaire, int $erreur, int $taille)
    {
        if ($taille < 0) {
            throw new InvalidArgumentException('La taille du fichier ne peut pas etre negative.');
        }
        $this->nomOriginal = $nomOriginal;
        $this->typeDeclare = $typeDeclare;
        $this->cheminTemporaire = $cheminTemporaire;
        $this->erreur = $erreur;
        $this->taille = $taille;
    }

    public function nomOriginal(): string
    {
        return $this->nomOriginal;
    }

    /** Type MIME annonce par le client ; il doit etre verifie cote serveur avant usage. */
    public function typeDeclare(): string
    {
        return $this->typeDeclare;
    }

    /** Chemin temporaire PHP ; il n'est exploitable que si erreur() vaut UPLOAD_ERR_OK. */
    public function cheminTemporaire(): string
    {
        return $this->cheminTemporaire;
    }

    public function erreur(): int
    {
        return $this->erreur;
    }

    public function taille(): int
    {
        return $this->taille;
    }

    public function estRecu(): bool
    {
        return $this->erreur === UPLOAD_ERR_OK;
    }
}
