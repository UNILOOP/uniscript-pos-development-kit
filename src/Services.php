<?php

declare(strict_types=1);

namespace UniScript\Integration;

interface Services
{
    public function request(string $service, string $action, array $arguments): array;
}
