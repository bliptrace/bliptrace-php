<?php

declare(strict_types=1);

namespace G8Scope\Php\Transport;

use Closure;
use G8Scope\Php\Config;

/**
 * Decides whether a failed ingest attempt should be retried, and how long to wait.
 *
 * The SDK now owns "the backend might be briefly down or shedding (429)" — the
 * responsibility nadi's shipper held in its own backoff loop. Strategy mirrors that:
 * exponential backoff (base × 2^attempt), ±25% jitter to avoid a synchronised
 * thundering herd, and a hard cap on both attempts and delay so capturing can never
 * block the monitored app for long. Retries are only safe because the envelope's
 * event_id is reused across attempts and the ingest API dedupes on it (spec §10).
 *
 * Pure and immutable: no I/O, no sleeping — that lives in the transport.
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly int $baseMs = 100,
        public readonly int $capMs = 2000,
    ) {}

    /** Build from the SDK config (framework adapters pass their own values). */
    public static function fromConfig(Config $config): self
    {
        return new self($config->maxRetries, $config->retryBaseMs, $config->retryCapMs);
    }

    /**
     * Should we retry after seeing $status, having already done $attempt retries?
     *
     * Retryable: transport errors / timeouts (status 0), 408, 429, and any 5xx.
     * Never retry a 2xx (success) or other 4xx (the request itself is bad).
     */
    public function shouldRetry(int $status, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return $this->isRetryable($status);
    }

    public function isRetryable(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * Delay before the next attempt, in milliseconds.
     *
     * A server-sent Retry-After (seconds) wins, capped to capMs so a hostile or
     * misconfigured header can't stall the app. Otherwise: exponential backoff with
     * ±25% jitter, capped at capMs. $jitter returns a float in [0,1) and is injectable
     * for deterministic tests; it defaults to a uniform random draw.
     */
    public function delayMs(int $attempt, ?int $retryAfterSec = null, ?Closure $jitter = null): int
    {
        if ($retryAfterSec !== null && $retryAfterSec > 0) {
            return min($this->capMs, $retryAfterSec * 1000);
        }

        $exp = min($this->capMs, $this->baseMs * (2 ** max(0, $attempt)));
        $rand = $jitter !== null ? $jitter() : (mt_rand() / mt_getrandmax());
        $delta = (int) round($exp * 0.25 * (2 * $rand - 1)); // ±25%

        return max(0, min($this->capMs, $exp + $delta));
    }
}
