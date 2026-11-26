<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\SessionInvalideException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Cycle de vie des jetons (CDC §11.4, ADR-0003) : ouverture, rotation, déconnexion.
 *
 *  - jeton d'accès : JWT de 15 min, révocable par son jti ou par sa famille ;
 *  - jeton de rafraîchissement : 64 caractères hexadécimaux tirés de random_bytes, valables 7 jours,
 *    à usage unique ; seule leur empreinte SHA-256 est stockée ;
 *  - réutilisation d'un jeton déjà consommé : toute la famille est révoquée, jetons d'accès compris.
 */
final class SessionService
{
    public const DUREE_ACCES = 900;           // 15 min
    public const DUREE_INTERMEDIAIRE = 300;   // 5 min : le temps de saisir un code TOTP

    private const FORMAT_JETON = '/^[0-9a-f]{64}$/';

    public function __construct(
        private readonly StockRefresh $refresh,
        private readonly RevocationJwt $revocation,
        private readonly EmetteurJetonAcces $emetteur,
        private readonly ComptesUtilisateurs $comptes,
        private readonly ClockInterface $horloge,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ouvre une nouvelle session (nouvelle famille de jetons).
     *
     * @param bool|null $a2fValide vrai quand le second facteur vient d'être vérifié (A2fService). Par défaut, la revendication reflète
     *                             l'état du compte (a2f_active) : un compte à A2F n'atteint cette méthode qu'après son code
     *                             (la connexion ne lui remet qu'un jeton intermédiaire), les autres n'ont rien à vérifier
     */
    public function ouvrir(Compte $compte, ?bool $a2fValide = null): Session
    {
        $familleId = self::uuidV4();
        $jetonRefresh = self::nouveauJeton();
        $this->refresh->enregistrer(self::empreinte($jetonRefresh), $compte->id, $familleId);

        return $this->session($compte, $familleId, $jetonRefresh, $a2fValide ?? $compte->a2fActive);
    }

    /** Jeton intermédiaire de la connexion en deux étapes : portée « a2f », 5 minutes, aucun refresh associé. */
    public function emettreJetonIntermediaire(Compte $compte): string
    {
        return $this->emetteur->emettre($compte, TypeJeton::A2f, self::jti(), self::uuidV4(), self::DUREE_INTERMEDIAIRE);
    }

    /**
     * Échange un jeton de rafraîchissement contre une nouvelle paire de jetons.
     *
     * @throws SessionInvalideException jeton malformé, inconnu, expiré, déjà utilisé ou compte désactivé
     */
    public function rafraichir(string $jetonRefresh): Session
    {
        if (1 !== preg_match(self::FORMAT_JETON, $jetonRefresh)) {
            throw new SessionInvalideException();   // jamais de requête Redis pour une valeur forgée
        }

        $nouveauJeton = self::nouveauJeton();
        $rotation = $this->refresh->faireTourner(self::empreinte($jetonRefresh), self::empreinte($nouveauJeton));

        if (Rotation::Reutilisation === $rotation->issue) {
            $this->revocation->revoquerFamille((string) $rotation->familleId, self::DUREE_ACCES);
            $this->logger->warning('Réutilisation d\'un jeton de rafraîchissement : session révoquée', [
                'familleId' => $rotation->familleId,
            ]);
            throw new SessionInvalideException();
        }
        if (Rotation::Reussie !== $rotation->issue) {
            throw new SessionInvalideException();
        }

        $compte = $this->comptes->parId((string) $rotation->utilisateurId);
        if (null === $compte || !$compte->actif) {
            // Compte supprimé ou désactivé depuis la dernière connexion : la session s'arrête là.
            $this->revoquerSession((string) $rotation->familleId);
            throw new SessionInvalideException();
        }

        // Une session ne s'ouvre pour un compte à A2F qu'après le code : la renouveler garde donc la revendication.
        return $this->session($compte, (string) $rotation->familleId, $nouveauJeton, $compte->a2fActive);
    }

    /**
     * Déconnexion : le jeton de rafraîchissement est supprimé et le jeton d'accès en cours est révoqué
     * jusqu'à son expiration (CDC §11.4).
     */
    public function deconnecter(string $jti, int $expiration, string $familleId): void
    {
        $this->revocation->revoquerJeton($jti, max(1, $expiration - $this->horloge->now()->getTimestamp()));
        $this->revoquerSession($familleId);
    }

    private function revoquerSession(string $familleId): void
    {
        $this->refresh->revoquerFamille($familleId);
        $this->revocation->revoquerFamille($familleId, self::DUREE_ACCES);
    }

    private function session(Compte $compte, string $familleId, string $jetonRefresh, bool $a2fValide): Session
    {
        $jetonAcces = $this->emetteur->emettre($compte, TypeJeton::Acces, self::jti(), $familleId, self::DUREE_ACCES, $a2fValide);

        return new Session($compte, $jetonAcces, self::DUREE_ACCES, $jetonRefresh);
    }

    public static function empreinte(string $jetonRefresh): string
    {
        return hash('sha256', $jetonRefresh);
    }

    private static function nouveauJeton(): string
    {
        return bin2hex(random_bytes(32));   // 256 bits d'entropie
    }

    private static function jti(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function uuidV4(): string
    {
        $octets = random_bytes(16);
        $octets[6] = \chr((\ord($octets[6]) & 0x0F) | 0x40);
        $octets[8] = \chr((\ord($octets[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($octets), 4));
    }
}
