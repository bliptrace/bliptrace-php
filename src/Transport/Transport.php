<?php

declare(strict_types=1);

namespace G8Scope\Php\Transport;

/** Ships an envelope to the ingest API. Implementations must never throw. */
interface Transport
{
    /** @param array<string,mixed> $envelope */
    public function send(string $endpoint, string $key, array $envelope, int $timeout): void;
}
