<?php

declare(strict_types=1);

namespace G8Scope\Php\Transport;

/** Dependency-free transport using ext-curl. Guard-wrapped: never throws. */
class CurlTransport implements Transport
{
    public function __construct(
        private readonly RetryPolicy $policy = new RetryPolicy,
    ) {}

    /** Accept the full ingest URL (1.3 default) or a bare base URL (pre-1.3 style). */
    public static function url(string $endpoint): string
    {
        $url = rtrim($endpoint, '/');

        return str_ends_with($url, '/v1/ingest') ? $url : $url.'/v1/ingest';
    }

    public function send(string $endpoint, string $key, array $envelope, int $timeout): void
    {
        try {
            $url = self::url($endpoint);
            $body = json_encode($envelope, JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                return;
            }

            // Retry loop. The same body (hence the same event_id) is replayed on each
            // attempt, so the ingest API's idempotency dedupe makes this safe — a retry
            // never creates a duplicate occurrence (spec §10).
            $attempt = 0;
            while (true) {
                [$status, $retryAfter] = $this->attempt($url, $body, $key, $timeout);
                if (! $this->policy->shouldRetry($status, $attempt)) {
                    return;
                }
                $this->sleepMs($this->policy->delayMs($attempt, $retryAfter));
                $attempt++;
            }
        } catch (\Throwable) {
            // Capturing must never break the monitored app.
        }
    }

    /**
     * Perform one HTTP attempt. Returns [httpStatus, retryAfterSeconds]; status is 0
     * on a transport error/timeout. Protected so tests can script responses.
     *
     * @return array{0:int,1:?int}
     */
    protected function attempt(string $url, string $body, string $key, int $timeout): array
    {
        $retryAfter = null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => max(1, $timeout),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Ingest-Key: '.$key,
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $value = trim(substr($header, 12));
                    if (is_numeric($value)) {
                        $retryAfter = (int) $value;
                    }
                }

                return strlen($header);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() is a deprecated no-op since PHP 8.0; the handle frees itself.

        return [$status, $retryAfter];
    }

    /** Sleep between attempts. Protected so tests can run without real delay. */
    protected function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
