<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\Platform;

/** Run with the incoming kit against the live ledger before it replaces the running one. Reads only. */
$state = getenv('PLATFORM_STATE') ?: '';
if ($state === '' || ! is_dir($state.'/deployments')) {
    fwrite(STDERR, "PLATFORM_STATE must name an existing platform ledger.\n");
    exit(2);
}
$refused = (new Platform($state))->ledgerIncompatibilities();
echo json_encode(['result' => $refused === [] ? 'compatible' : 'incompatible', 'refused' => $refused], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
exit($refused === [] ? 0 : 1);
