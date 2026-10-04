<?php
declare(strict_types=1);

namespace PhpCleanCode\Infrastructure;

use PhpCleanCode\Application\Port\AuthentificationInterface;
use PhpCleanCode\Domain\Entite\Identite;

/**
 * Enveloppe qui retarde la construction du vrai authentificateur.
 *
 * NE PAS LA PRENDRE POUR UNE ELEGANCE GRATUITE. Elle repare un defaut precis.
 *
 * La racine de composition peut fournir cette enveloppe aux cas d'usage sans
 * construire immediatement l'authentificateur reel. Si un cas d'usage
 * n'appelle pas identifier(), la fabrique d'authentification n'est jamais
 * executee et ses dependances ne sont pas construites par cette fabrique.
 *
 * Avec cette enveloppe, le vrai authentificateur n'est construit que lorsque
 * identifier() est appele. Si un cas d'usage n'appelle jamais cette methode,
 * sa fabrique d'authentification n'est jamais executee.
 *
 * Cette enveloppe ne verifie pas elle-meme le jeton. Au premier appel,
 * l'authentificateur reel est construit avant que son identifier() soit
 * appele. Sa construction et celle de ses dependances doivent donc rester
 * sans effet de bord ; l'acces a la base pour retrouver l'acteur doit avoir
 * lieu apres la verification du jeton.
 *
 * @package PhpCleanCode\Infrastructure
 */
final class AuthentificationDifferee implements AuthentificationInterface
{
    /** @var callable(): AuthentificationInterface */
    private $construire;
    private $reel = null;

    /** @param callable(): AuthentificationInterface $construire */
    public function __construct(callable $construire)
    {
        $this->construire = $construire;
    }

    public function identifier(): Identite
    {
        if ($this->reel === null) {
            $fabrique    = $this->construire;
            $this->reel  = $fabrique();
        }
        return $this->reel->identifier();
    }
}

