<?php
declare(strict_types=1);

namespace PhpCleanCode\Domain\Entite;

/**
 * Le consommateur de l'API, une fois authentifie.
 *
 * Dans une application, construisez cette identite a partir d'un jeton
 * verifie ou d'une authentification reussie, jamais depuis un champ de
 * requete. Le constructeur public ne peut pas imposer cette provenance :
 * cette regle depend du cablage et des choix de l'application.
 *
 * Cette regle n'est pas theorique. Accepter un identifiant d'utilisateur
 * transmis dans le corps d'une requete -- et court-circuiter le jeton quand
 * il est present -- est une faille classique : n'importe quel appelant
 * authentifie agit alors au nom de n'importe qui.
 *
 * @package PhpCleanCode\Domain\Entite
 */
final class Identite
{
    private $id;
    private $nom;

    public function __construct(int $id, string $nom)
    {
        $this->id  = $id;
        $this->nom = $nom;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function nom(): string
    {
        return $this->nom;
    }
}

