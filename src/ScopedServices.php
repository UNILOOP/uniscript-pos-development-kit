<?php

declare(strict_types=1);

namespace UniScript\Integration;

use Closure;
use RuntimeException;

final class ScopedServices implements Services
{
    private int $remaining;
    public function __construct(private readonly Invocation $invocation, private readonly Closure $transport)
    {
        $this->remaining = $invocation->envelope['request_budget'];
    }

    public function request(string $service, string $action, array $arguments): array
    {
        if (!in_array($service.'.'.$action, ['storage.read', 'storage.write', 'resources.read', 'commands.propose', 'http.request'], true)) {
            throw new RuntimeException('unsupported_service');
        }
        if (array_intersect(array_keys($arguments), ['organization_id', 'location_id', 'environment', 'connection_id', 'package_id', 'credential', 'token', 'secret']) !== []) {
            throw new RuntimeException('scope_or_secret_override');
        }
        if ($this->remaining-- <= 0 || strtotime($this->invocation->envelope['deadline']) <= time()) {
            throw new RuntimeException('invocation_budget_exhausted');
        }
        return ($this->transport)([
            'invocation_id' => $this->invocation->envelope['invocation_id'],
            'grant' => $this->invocation->envelope['service_grant'],
            'service' => $service,
            'action' => $action,
            'arguments' => $arguments,
        ]);
    }
}
