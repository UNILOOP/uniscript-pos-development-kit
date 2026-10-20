<?php

declare(strict_types=1);

namespace UniScript\Integration;

use RuntimeException;

final class Runtime
{
    public function __construct(private readonly string $packageRoot, private readonly string $executionRevision) {}

    public function invoke(Invocation $invocation, Services $services): Result
    {
        $manifest = Manifest::read($this->packageRoot);
        if ($manifest['package_id'] !== $invocation->envelope['package_id'] || !hash_equals($this->executionRevision, $invocation->envelope['execution_revision'])) {
            throw new RuntimeException('runtime_binding_mismatch');
        }
        $operation = array_find($manifest['operations'], static fn(array $operation): bool => $operation['key'] === $invocation->operation());
        if ($operation === null) {
            return new Result('unsupported_operation', 'operation.unsupported');
        }
        $handlers = require $this->packageRoot.'/'.$manifest['runtime']['entrypoint'];
        $handler = $handlers[$operation['key']] ?? null;
        if (!is_callable($handler)) {
            return new Result('permanent_failure', 'handler.missing');
        }
        $result = $handler($invocation, $services);
        if (!$result instanceof Result) {
            return new Result('permanent_failure', 'handler.invalid_result');
        }
        if ($result->kind === 'partial') {
            return new Result('permanent_failure', 'partial.not_certified');
        }
        return $result;
    }
}
