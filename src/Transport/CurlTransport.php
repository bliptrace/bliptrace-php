<?php

declare(strict_types=1);

namespace G8Scope\Php\Transport;

/** Dependency-free transport using ext-curl. Guard-wrapped: never throws. */
final class CurlTransport implements Transport
{
    public function send(string $endpoint, string $key, array $envelope, int $timeout): void
    {
        try {
            $url = rtrim($endpoint, '/').'/v1/ingest';
            $body = json_encode($envelope, JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                return;
            }

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
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Capturing must never break the monitored app.
        }
    }
}
