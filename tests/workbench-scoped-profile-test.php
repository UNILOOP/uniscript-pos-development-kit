<?php

declare(strict_types=1);

define('WORKBENCH_FIXTURES_ONLY', true);
require __DIR__.'/workbench-test.php';

use UniScript\Integration\DevelopmentServices;
use UniScript\Integration\InboundProfile;
use UniScript\Integration\Manifest;
use UniScript\Integration\Result;
use UniScript\Integration\Workbench;

$root = sys_get_temp_dir().'/workbench-scoped-profile-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/reference.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest = Manifest::read(dirname(__DIR__).'/examples/reference');
$envelope = static fn (string $operation = 'orders.submit'): array => ['protocol_version' => '1', 'invocation_id' => bin2hex(random_bytes(16)), 'command_id' => 'command', 'attempt_id' => 'attempt', 'correlation_id' => 'correlation', 'package_id' => $fixture['package_id'], 'execution_revision' => 'execution', 'connection_id' => 'connection', 'organization_id' => 'organization', 'location_id' => 'location', 'environment' => 'sandbox', 'deployment_environment' => 'local', 'actor_id' => 'pharmacy', 'operation' => $operation, 'schema_version' => '1', 'routing_revision' => '1', 'configuration_revision' => '1', 'mapping_revision' => '1', 'credential_revision' => '1', 'idempotency_key' => 'same-logical-order', 'deadline' => gmdate('Y-m-d\TH:i:s\Z', time() + 60), 'request_budget' => 100, 'input' => [], 'service_grant' => bin2hex(random_bytes(32))];
$profiles = ['reviewed.vendor' => ['host_result_contract' => 'vendor_json', 'operations' => ['orders.submit', 'vendor.stage'], 'routes' => ['patient' => ['mode' => 'order', 'reference_kind' => 'patient'], 'prescriptions' => ['mode' => 'order', 'reference_kind' => 'order', 'operation_policies' => ['vendor.stage' => ['order_acceptance' => false]]], 'inspect' => ['mode' => 'read', 'reference_fields' => ['id' => 'patient']]]]];
try {
    $env = $envelope();
    $deployment = ['manifest' => $manifest, 'package_root' => dirname(__DIR__).'/examples/reference'];
    $broker = new DevelopmentServices($root.'/broker.sqlite', $env, $deployment, $fixture['configuration'], $fixture['credentials'], [], ['profiles' => $profiles]);
    $call = static fn (DevelopmentServices $service, array $envelope, string $selector, array $body = [], array $parameters = []): array => $service->request(['invocation_id' => $envelope['invocation_id'], 'grant' => $envelope['service_grant'], 'service' => 'http', 'action' => 'request', 'arguments' => ['profile' => 'reviewed.vendor', 'operation_key' => $envelope['operation'], 'idempotency_key' => $envelope['idempotency_key'], 'parameters' => ['operation_id' => $selector, ...$parameters], 'body' => $body]]);
    $patient = $call($broker, $env, 'patient', ['synthetic_patient' => true]);
    workbenchCheck(isset($patient['patient_reference_handle']) && ! isset($patient['external_reference_handle']), 'Typed patient handle is not an order acceptance');
    $prescriptions = $call($broker, $env, 'prescriptions', ['prescriptions' => [[], []]], ['patient_reference_handle' => $patient['patient_reference_handle']]);
    workbenchCheck(count($prescriptions['prescription_reference_handles']) === 2 && isset($prescriptions['external_reference_handle']), 'One logical invocation has separate patient and prescription claims');
    workbenchCheck($call($broker, $env, 'patient', ['synthetic_patient' => true])['patient_reference_handle'] === $patient['patient_reference_handle'], 'Route replay retains typed handle');
    workbenchDenied(fn () => $call($broker, $env, 'patient', ['different' => true]), 'development_idempotency_conflict');
    workbenchDenied(fn () => $call($broker, $env, 'inspect', ['id' => $prescriptions['prescription_reference_handles'][0]]), 'development_owned_reference_scope_denied');
    $other = new DevelopmentServices($root.'/broker.sqlite', $env, $deployment, $fixture['configuration'], ['api_key' => 'different-synthetic-principal'], [], ['profiles' => $profiles]);
    workbenchDenied(fn () => $call($other, $env, 'inspect', ['id' => $patient['patient_reference_handle']]), 'development_owned_reference_scope_denied');
    $sameAccount = new DevelopmentServices($root.'/broker.sqlite', $env, $deployment, array_reverse($fixture['configuration'], true), array_reverse($fixture['credentials'], true), [], ['profiles' => $profiles]);
    workbenchCheck($call($sameAccount, $env, 'inspect', ['id' => $patient['patient_reference_handle']])['status'] === 'accepted', 'Property ordering does not change the immutable account fingerprint');
    $otherAccount = new DevelopmentServices($root.'/broker.sqlite', $env, $deployment, [...$fixture['configuration'], 'account_id' => 'different-account'], $fixture['credentials'], [], ['profiles' => $profiles]);
    workbenchDenied(fn () => $call($otherAccount, $env, 'inspect', ['id' => $patient['patient_reference_handle']]), 'development_owned_reference_scope_denied');
    $stageEnvelope = $envelope('vendor.stage');
    $stageBroker = new DevelopmentServices($root.'/broker.sqlite', $stageEnvelope, $deployment, $fixture['configuration'], $fixture['credentials'], [], ['profiles' => $profiles]);
    $stage = $call($stageBroker, $stageEnvelope, 'prescriptions', ['prescriptions' => [[]]], ['patient_reference_handle' => $patient['patient_reference_handle']]);
    workbenchCheck(isset($stage['prescription_reference_handles']) && ! isset($stage['external_reference_handle']) && $stageBroker->evidence()['external_references'] === [], 'Reviewed non-order route policy creates no canonical acceptance');
    foreach (['basic', 'hmac_sha256', 'header_secret'] as $authentication) {
        $profile = [...$fixture['inbound_profile'], 'authentication' => $authentication, 'prescription_reference_field' => 'prescriptionId'];
        workbenchCheck(InboundProfile::validate($profile, $fixture['package_id']) === $profile, 'Portable callback descriptor: '.$authentication);
        $runtime = new class(new WorkbenchFixtureRuntime($root.'/'.$authentication.'/builds'))
        {
            public array $evidence = [];

            public function __construct(private WorkbenchFixtureRuntime $fixture) {}

            public function prepare(string $source, string $execution): array
            {
                return $this->fixture->prepare($source, $execution);
            }

            public function invoke(array $deployment, string $operation, array $input, array $config, array $credentials, array $resources, array $scope): array
            {
                $result = $operation === 'inbound.decode' ? [] : $this->fixture->invoke($deployment, $operation, $input, $config, $credentials, $resources, $scope);
                $this->evidence = $operation === 'inbound.decode' ? [] : $this->fixture->lastEvidence();
                if ($operation === 'orders.submit') {
                    $raw = 'synthetic-vendor-'.$input['order_id'];
                    $this->evidence['prescription_references'] = [['handle' => 'rx-one', 'reference' => $raw.'-rx-1'], ['handle' => 'rx-two', 'reference' => $raw.'-rx-2']];
                }
                if ($operation === 'inbound.decode') {
                    $source = $input['events'][0];
                    $this->evidence['proposals'] = [['command' => 'normalized_event', 'receipt_handle' => $input['receipt_handle'], 'event' => ['external_order_reference' => $source['orderId'] ?? $source['prescriptionId'], 'event_id' => $source['eventId'], 'normalized_status' => $source['status'], 'fill_reference' => $source['prescriptionId'], 'tracking_number' => $source['trackingNumber']]]];
                    $result = (new Result('success', 'reference.decoded'))->jsonSerialize();
                }

                return $result;
            }

            public function lastEvidence(): array
            {
                return $this->evidence;
            }
        };
        $workbench = new Workbench($root.'/'.$authentication.'/state', $runtime, [[...$fixture, 'inbound_profile' => $profile]]);
        $package = $workbench->load(dirname(__DIR__).'/examples/reference');
        $connection = $workbench->dispatch('connection.create', ['execution_revision' => $package['execution_revision'], 'config' => $fixture['configuration'], 'credentials' => $fixture['credentials']]);
        $scope = ['connection_id' => $connection['uuid']];
        $ids = $workbench->snapshot()['fixtures'];
        $workbench->dispatch('connection.health', $scope);
        $workbench->dispatch('connection.test', $scope);
        $workbench->dispatch('connection.activate', $scope);
        $workbench->dispatch('mapping.save', [...$scope, 'catalog_item_id' => $ids['catalog_item_id'], 'expected_revision' => 0, 'values' => $fixture['mapping']]);
        $order = $workbench->dispatch('order.submit', [...$scope, 'sender_id' => $ids['sender_id'], 'catalog_item_id' => $ids['catalog_item_id'], 'idempotency_key' => 'generic-multiple-rx', 'input' => $fixture['order']]);
        $endpoint = $workbench->dispatch('webhook.create', [...$scope, 'username' => 'synthetic-inbound', 'password' => 'synthetic-independent-inbound-secret']);
        $send = static function (array $payload, bool $valid = true) use ($workbench, $endpoint): array {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $secret = $valid ? 'synthetic-independent-inbound-secret' : 'wrong-secret';

            return $workbench->receive($endpoint['uuid'], 'synthetic-inbound', $secret, $body, ['x-hmac-signature' => hash_hmac('sha256', $body, $secret), 'x-api-key' => $secret]);
        };
        $raw = 'synthetic-vendor-'.$order['uuid'];
        $wire = ['orderId' => $raw, 'prescriptionId' => $raw.'-rx-1', 'eventId' => 'first', 'status' => 'delivered', 'trackingNumber' => 'SYNTHETIC-PARCEL'];
        workbenchDenied(fn () => $send($wire, false), 'webhook_unauthorized');
        workbenchDenied(fn () => $send([...$wire, 'prescriptionId' => 'foreign-rx']), 'callback_prescription_reference_not_owned');
        workbenchCheck($send($wire)['status'] === 'processed' && $workbench->snapshot()['orders'][0]['status'] === 'ready', 'First delivered prescription cannot complete the order: '.$authentication);
        unset($wire['orderId']);
        $wire['prescriptionId'] = $raw.'-rx-2';
        $wire['eventId'] = 'second';
        workbenchCheck($send($wire)['status'] === 'processed' && $workbench->snapshot()['orders'][0]['status'] === 'completed', 'Prescription-only callback completes the remaining prescription: '.$authentication);
        workbenchCheck(count($workbench->snapshot()['orders'][0]['fulfillments']) === 1, 'Both prescriptions share a delivered parcel');
    }
    echo json_encode(['result' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
} finally {
    workbenchRemove($root);
}
