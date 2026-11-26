<?php

declare(strict_types=1);

namespace App\Service\A2f;

use App\Exception\A2fEtatIncompatibleException;
use App\Exception\CodeA2fInvalideException;
use App\Exception\TropDeTentativesException;
use App\Security\Utilisateur;
use App\Service\Audit\JournalAudit;
use App\Service\Auth\Compte;
use App\Service\Auth\LimiteurDebit;
use App\Service\Auth\RevocationJwt;
use App\Service\Auth\Session;
use App\Service\Auth\SessionService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Seconde étape de la connexion (CDC §11.4, B9) : enrôlement TOTP, vérification d'un code, codes de secours.
 *
 * La connexion par mot de passe ne donne qu'un jeton intermédiaire (portée « a2f », 5 min, valable sur les seules
 * routes /api/connexion/a2f). Ce service échange ce jeton, une fois, contre la session définitive, dont le jeton
 * d'accès porte la revendication « a2f » : seule elle ouvre le back-office (RbacVoter).
 *
 * Sécurité :
 *  - tentatives limitées par compte (rl:a2f:{userId}, 5 par 15 min, 429 + Retry-After) : un code à 6 chiffres
 *    ne se force pas ;
 *  - un code TOTP ne sert qu'une fois (a2f:used:{userId}:{pas}) ;
 *  - un code de secours est à usage unique (UPDATE atomique) et stocké haché (argon2id) ;
 *  - un secret illisible compte comme un code faux : jamais de repli permissif (Fail Securely) ;
 *  - rien de sensible dans le journal d'audit ni dans les logs : ni secret, ni code.
 */
final class A2fService
{
    /** rl:a2f:{userId} : 5 tentatives par fenêtre de 15 minutes. */
    public const TENTATIVES_MAX = 5;
    public const FENETRE_SECONDES = 900;

    public function __construct(
        private readonly ComptesA2f $donnees,
        private readonly Totp $totp,
        private readonly ChiffrementSecretTotp $chiffrement,
        private readonly UsageUniqueTotp $usages,
        private readonly LimiteurDebit $limiteur,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly SessionService $sessions,
        private readonly RevocationJwt $revocation,
        private readonly JournalAudit $audit,
        private readonly ClockInterface $horloge,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Commence l'enrôlement : génère un nouveau secret, le stocke chiffré (en attente) et le renvoie pour le QR code.
     * Chaque appel remplace le secret en attente ; un secret actif n'est jamais touché.
     *
     * @throws A2fEtatIncompatibleException l'A2F est déjà active (409)
     */
    public function demarrerEnrolement(Compte $compte): EnrolementA2f
    {
        if ($compte->a2fActive) {
            throw new A2fEtatIncompatibleException(A2fEtatIncompatibleException::DEJA_ACTIVE);
        }

        $secret = $this->totp->nouveauSecret();
        if (!$this->donnees->enregistrerSecretEnAttente($compte->id, $this->chiffrement->chiffrer($secret, $compte->id))) {
            throw new A2fEtatIncompatibleException(A2fEtatIncompatibleException::DEJA_ACTIVE);
        }

        return new EnrolementA2f($secret, $this->totp->uriProvisionnement($secret, $compte->email));
    }

    /**
     * Confirme l'enrôlement avec un premier code, active l'A2F, génère les 10 codes de secours et ouvre la session.
     *
     * @throws TropDeTentativesException     trop de tentatives (429)
     * @throws A2fEtatIncompatibleException  A2F déjà active, ou aucun enrôlement commencé (409)
     * @throws CodeA2fInvalideException      code faux, expiré ou déjà utilisé (401)
     */
    public function confirmerEnrolement(Compte $compte, string $code, string $ip, string $jetonIntermediaireJti, int $jetonIntermediaireExp): ActivationA2f
    {
        if ($compte->a2fActive) {
            throw new A2fEtatIncompatibleException(A2fEtatIncompatibleException::DEJA_ACTIVE);
        }
        $this->limiter($compte);

        $chiffre = $this->donnees->secretChiffre($compte->id) ?? throw new A2fEtatIncompatibleException(A2fEtatIncompatibleException::ENROLEMENT_ABSENT);
        if (!$this->codeTotpValide($compte, $chiffre, $code)) {
            $this->echec($compte, $ip);
            throw new CodeA2fInvalideException();
        }

        $codes = CodesSecours::generer();
        $hasher = $this->hashers->getPasswordHasher(Utilisateur::class);
        $hachages = array_map(static fn (string $c): string => $hasher->hash((string) CodesSecours::normaliser($c)), $codes);
        if (!$this->donnees->activer($compte->id, $hachages)) {
            throw new A2fEtatIncompatibleException(A2fEtatIncompatibleException::DEJA_ACTIVE);
        }

        $this->audit->tracer(JournalAudit::A2F_ACTIVATION, 'utilisateur', $compte->id, $compte->id, $ip);
        $this->revoquerJetonIntermediaire($jetonIntermediaireJti, $jetonIntermediaireExp);

        return new ActivationA2f($this->sessions->ouvrir($compte->avecA2fActive(), a2fValide: true), $codes);
    }

    /**
     * Vérifie le code (TOTP à 6 chiffres ou code de secours) et échange le jeton intermédiaire contre la session.
     *
     * @throws TropDeTentativesException    trop de tentatives (429)
     * @throws A2fEtatIncompatibleException le compte n'a pas d'A2F active (409)
     * @throws CodeA2fInvalideException     code faux, expiré, déjà utilisé ou secret illisible (401)
     */
    public function valider(Compte $compte, string $saisie, string $ip, string $jetonIntermediaireJti, int $jetonIntermediaireExp): Session
    {
        if (!$compte->a2fActive) {
            throw new A2fEtatIncompatibleException(A2fEtatIncompatibleException::NON_ACTIVE);
        }
        $this->limiter($compte);

        $saisie = str_replace(' ', '', $saisie);
        $secoursUtilise = false;
        if (1 === preg_match('/^[0-9]{6}$/', $saisie)) {
            $chiffre = $this->donnees->secretChiffre($compte->id);
            $valide = null !== $chiffre && $this->codeTotpValide($compte, $chiffre, $saisie);
        } elseif (null !== ($secours = CodesSecours::normaliser($saisie))) {
            $valide = $secoursUtilise = $this->codeSecoursValide($compte, $secours);
        } else {
            $valide = false;
        }

        if (!$valide) {
            $this->echec($compte, $ip);
            throw new CodeA2fInvalideException();
        }
        if ($secoursUtilise) {
            $this->audit->tracer(JournalAudit::A2F_CODE_SECOURS, 'utilisateur', $compte->id, $compte->id, $ip);
        }
        $this->revoquerJetonIntermediaire($jetonIntermediaireJti, $jetonIntermediaireExp);

        return $this->sessions->ouvrir($compte, a2fValide: true);
    }

    private function limiter(Compte $compte): void
    {
        $this->limiteur->enregistrerTentative('rl:a2f:'.$compte->id, self::TENTATIVES_MAX, self::FENETRE_SECONDES);
    }

    private function codeTotpValide(Compte $compte, string $secretChiffre, string $code): bool
    {
        try {
            $secret = $this->chiffrement->dechiffrer($secretChiffre, $compte->id);
        } catch (SecretTotpIllisibleException) {
            // Secret altéré ou donnée factice : on refuse, on le journalise (sans le secret) et on ne se replie sur rien.
            $this->logger->error('Secret TOTP illisible : code refusé', ['utilisateurId' => $compte->id]);

            return false;
        }

        $pas = $this->totp->verifier($secret, $code);

        // Le pas est réservé seulement si le code est bon : un code déjà accepté ne sert pas deux fois.
        return null !== $pas && $this->usages->reserver($compte->id, $pas);
    }

    private function codeSecoursValide(Compte $compte, string $codeNormalise): bool
    {
        $hasher = $this->hashers->getPasswordHasher(Utilisateur::class);
        foreach ($this->donnees->codesSecoursDisponibles($compte->id) as $disponible) {
            if ($hasher->verify($disponible['hache'], $codeNormalise)) {
                return $this->donnees->consommerCodeSecours($disponible['id']);   // faux si un autre appel l'a consommé entre-temps
            }
        }

        return false;
    }

    private function echec(Compte $compte, string $ip): void
    {
        $this->audit->tracer(JournalAudit::A2F_ECHEC, 'utilisateur', $compte->id, $compte->id, $ip);
    }

    private function revoquerJetonIntermediaire(string $jti, int $expiration): void
    {
        // Usage unique : le jeton intermédiaire ne peut pas ouvrir une seconde session.
        $this->revocation->revoquerJeton($jti, max(1, $expiration - $this->horloge->now()->getTimestamp()));
    }
}
