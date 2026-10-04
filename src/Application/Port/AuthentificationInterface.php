<?php
declare(strict_types=1);

namespace PhpCleanCode\Application\Port;

use PhpCleanCode\Domain\Entite\Identite;
use PhpCleanCode\Domain\ErreurMetier;

/**
 * Qui appelle ?
 *
 * Un cas d'usage qui a besoin de savoir a qui imputer une action depend de
 * cette interface, et de rien d'autre. Il ignore s'il y a un jeton, un
 * en-tete, un cookie ou une session derriere.
 *
 * GARDEZ LA CONSTRUCTION SANS EFFET DE BORD. La racine de composition peut
 * fournir l'authentification aux cas d'usage sans que cette construction
 * ouvre une connexion. Ensuite, l'implementation doit verifier le jeton
 * avant de resoudre l'acteur en base : une requete sans jeton peut ainsi
 * recevoir "jeton absent" sans dependre de la disponibilite de la base.
 *
 * @package PhpCleanCode\Application\Port
 */
interface AuthentificationInterface
{
    /**
     * @throws ErreurMetier JETON_ABSENT, JETON_MALFORME, JETON_MAL_SIGNE,
     *                      JETON_EXPIRE ou ACTEUR_INTROUVABLE.
     */
    public function identifier(): Identite;
}


