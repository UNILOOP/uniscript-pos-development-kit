<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\CommandProposals;
use UniScript\Integration\JsonDocument;

$cases = JsonDocument::decode(file_get_contents(dirname(__DIR__).'/contracts/normalized-event-examples.json'), true);
$checks = 0;
foreach ($cases as $case) {
    $accepted = false;
    try {
        $actual = CommandProposals::normalizedEvent($case['arguments']);
        $accepted = $actual === $case['arguments'];
    } catch (InvalidArgumentException) {
    }
    if ($accepted !== $case['valid']) {
        throw new RuntimeException('normalized_event_contract_mismatch: '.$case['name']);
    }
    $checks++;
}
echo 'Normalized-event proposal contract: '.$checks." checks passed.\n";
