<?php

declare(strict_types=1);

/*
 * Un acheteur du test de concurrence (tests/Integration/Concurrence/AchatDerniereCleTest) : processus PHP
 * indépendant, avec son propre noyau Symfony et sa propre connexion PostgreSQL, comme une requête HTTP.
 *
 *   php acheteur_concurrent.php <utilisateurId> <panierUuid> <verrouBarriere>
 *
 * Il attend la barrière (verrou consultatif PostgreSQL tenu par le test), puis passe commande avec le vrai
 * CommandeService et écrit son résultat en JSON sur la sortie standard. Tous les acheteurs partent ainsi
 * au même instant.
 */

use App\Kernel;
use App\Repository\UtilisateurRepository;
use App\Service\Catalogue\Langue;
use App\Service\Commande\CommandeService;
use App\Service\Panier\PanierId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Dotenv\Dotenv;

$racine = dirname(__DIR__, 3);
require $racine.'/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($racine.'/.env');

[, $utilisateurId, $panier, $verrou] = $argv + [null, '', '', '0'];
$kernel = new Kernel('test', false);
$kernel->boot();
$conteneur = $kernel->getContainer()->get('test.service_container');

$connexion = $conteneur->get(Connection::class);
$connexion->executeStatement('SELECT pg_advisory_lock_shared(CAST(:v AS bigint))', ['v' => $verrou]);   // barrière
$connexion->executeStatement('SELECT pg_advisory_unlock_shared(CAST(:v AS bigint))', ['v' => $verrou]);

$compte = $conteneur->get(UtilisateurRepository::class)->parId($utilisateurId);
try {
    $commande = $conteneur->get(CommandeService::class)->passer($compte, PanierId::visiteur($panier), false, Langue::Fr);
    echo json_encode(['resultat' => 'commande', 'reference' => $commande->reference]);
} catch (Throwable $e) {
    echo json_encode(['resultat' => (new ReflectionClass($e))->getShortName()]);
}
