<?php
/**
 * Planificateur, à lancer toutes les 15 minutes.
 * Hostinger (hPanel > Avancé > Tâches Cron), commande :
 *   /usr/bin/php /home/<compte>/public_html/musow/cron.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès réservé à la tâche planifiée.');
}
require __DIR__ . '/src/bootstrap.php';

$verrou = fopen(MK_ROOT . '/storage/cron.lock', 'c');
if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) {
    exit(0); // une exécution précédente tourne encore
}
try {
    Installation::semerBibliotheque(null); // ajoute les nouveaux messages des mises à jour (sans toucher aux existants)
    $bilan = Planificateur::executer();
    $resume = implode(', ', array_map(static fn($k, $v) => "$k=$v", array_keys($bilan), $bilan));
    log_tech('cron', $resume);
    echo $resume, PHP_EOL;
} catch (Throwable $e) {
    log_tech('cron', 'Erreur : ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
}
