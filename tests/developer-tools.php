<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use UniScript\Integration\DeveloperTools;
use UniScript\Integration\InboundProfile;
use UniScript\Integration\Manifest;
use UniScript\Integration\Process;
use UniScript\Integration\Workbench;

$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$root = sys_get_temp_dir().'/pos-kit-authoring-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$project = $root.'/package';
$created = DeveloperTools::scaffold('acme/custom-pos', $project, 'custom-ui');
$check($created['package_id'] === 'acme/custom-pos', 'custom identity');
$check(json_decode(file_get_contents($project.'/integration.json'), true)['publisher'] === 'acme', 'publisher set');
$check(str_contains(file_get_contents($project.'/.gitignore'), '.env*'), 'new package repositories ignore local credentials and generated data');
$first = DeveloperTools::export($project, $root.'/first.zip');
$second = DeveloperTools::export($project, $root.'/second.zip');
$check($first['source_sha256'] === $second['source_sha256'], 'reproducible export');
$check($first['checks']['archive_intake'] === 'passed', 'actual archive inspection');
$check($first['final_approval'] === false, 'export does not certify or approve');
$requirements = ['contract_version' => 1, 'transport_profiles' => ['custom.orders'], 'verification_profile' => null, 'inbound_profiles' => []];
file_put_contents($project.'/host-requirements.json', json_encode($requirements, JSON_THROW_ON_ERROR));
$requested = DeveloperTools::export($project, $root.'/requested.zip');
$check($requested['requested_host_profiles'] === $requirements, 'submission report carries exact requested profile names without authorizing them');
$check(is_file($root.'/first.zip.report.json'), 'separate submission report');
foreach ([['new', 'acme/custom', '../escaped'], ['package', '../escaped', 'result.zip'], ['package', 'package', '../escaped.zip']] as $arguments) {
    try {
        Process::run(['sh', dirname(__DIR__).'/dev-kit', ...$arguments], 5);
        throw new RuntimeException('Unsafe wrapper arguments accepted.');
    } catch (RuntimeException $exception) {
        $check(str_contains($exception->getMessage(), 'Workspace paths cannot') || str_contains($exception->getMessage(), 'without a directory prefix'), 'wrapper rejects path traversal before calling Docker');
    }
}
$reference = DeveloperTools::scaffold('acme/second-vendor', $root.'/second-vendor', 'reference', $root.'/second-vendor-fixture.json');
$check(json_decode(file_get_contents($reference['local_fixture']), true)['package_id'] === 'acme/second-vendor', 'companion fixture follows custom publisher identity');
$check(! is_file($root.'/second-vendor/second-vendor-fixture.json'), 'development fixture stays outside deliverable');
$companion = json_decode(file_get_contents($reference['local_fixture']), true, flags: JSON_THROW_ON_ERROR);
$check($companion['inbound_profile']['package_ids'] === ['acme/second-vendor'], 'companion inbound profile grants only the newly scaffolded identity');
$check(! str_contains(json_encode($companion, JSON_THROW_ON_ERROR), '"uniscript/reference-pos"'), 'supported companion identity scopes contain no original template package identity');
$check(InboundProfile::validate($companion['inbound_profile'], 'acme/second-vendor') === $companion['inbound_profile'], 'generated inbound profile validates for its exact package identity');
try {
    InboundProfile::validate($companion['inbound_profile'], 'uniscript/reference-pos');
    throw new RuntimeException('Scaffolded fixture retained template callback authority.');
} catch (InvalidArgumentException $exception) {
    $check($exception->getMessage() === 'inbound_profile_invalid', 'generated inbound profile rejects its original template identity');
}
$fixtureRuntime = new class
{
    public function prepare(string $source, string $execution): array
    {
        return ['execution_revision' => $execution, 'manifest' => Manifest::read($source), 'package_root' => $source, 'source_sha256' => hash_file('sha256', $source.'/integration.json'), 'status' => 'development_ready'];
    }
};
$workbench = new Workbench($root.'/scaffold-workbench', $fixtureRuntime, [$companion]);
$loaded = $workbench->load($reference['directory']);
$connection = $workbench->dispatch('connection.create', ['execution_revision' => $loaded['execution_revision'], 'config' => $companion['configuration'], 'credentials' => $companion['credentials']]);
$endpoint = $workbench->dispatch('webhook.create', ['connection_id' => $connection['uuid'], 'username' => 'scaffold-fixture', 'password' => 'independent-local-fixture-password-at-least-24']);
$check(str_starts_with($endpoint['path'], '/pos-webhooks/'), 'a scaffolded custom publisher connection creates its generic webhook URL');
$referenceExport = DeveloperTools::export($reference['directory'], $root.'/reference-export.zip');
$referenceZip = new ZipArchive;
$referenceZip->open($referenceExport['zip']);
$check($referenceZip->locateName('second-vendor-fixture.json') === false && $referenceZip->locateName('fixtures/reference.json') === false, 'custom publisher export remains source-only without companion fixtures');
$referenceZip->close();

$protected = $root.'/protected.txt';
file_put_contents($protected, 'Keep this unrelated file.');
symlink($protected, $root.'/linked.zip');
try {
    DeveloperTools::export($project, $root.'/linked.zip');
    throw new RuntimeException('Symlink artifact target accepted.');
} catch (InvalidArgumentException) {
    $check(file_get_contents($protected) === 'Keep this unrelated file.', 'export does not follow output symlink');
}
foreach ([static fn () => DeveloperTools::scaffold('acme/custom-pos', $project), static fn () => DeveloperTools::scaffold('../bad', $root.'/bad'), static fn () => DeveloperTools::export($project, $project.'/unsafe.zip')] as $operation) {
    try {
        $operation();
        throw new RuntimeException('Unsafe authoring input accepted.');
    } catch (InvalidArgumentException) {
        $assertions++;
    }
}
file_put_contents($project.'/.env', 'SYNTHETIC-SECRET-NOT-FOR-EXPORT');
try {
    DeveloperTools::export($project, $root.'/secret.zip');
    throw new RuntimeException('Secret-bearing source accepted.');
} catch (RuntimeException $exception) {
    $check(! is_file($root.'/secret.zip') && ! str_contains($exception->getMessage(), 'SYNTHETIC-SECRET-NOT-FOR-EXPORT'), 'unsafe source fails without artifact or secret output');
}
echo json_encode(['result' => 'passed', 'assertions' => $assertions], JSON_THROW_ON_ERROR)."\n";
