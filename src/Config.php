<?php

declare(strict_types=1);

namespace G8Scope\Php;

/** Immutable SDK configuration. Framework adapters build this from their config. */
final class Config
{
    /**
     * @param  array<int,string>  $ignoreExceptions
     * @param  array<int,string>  $scrubKeys
     */
    public function __construct(
        public readonly ?string $key,
        public readonly string $endpoint = 'https://ingest.scope.g8suite.com',
        public readonly string $environment = 'production',
        public readonly ?string $release = null,
        public readonly int $timeout = 3,
        public readonly int $maxFrames = 30,
        public readonly int $maxMessage = 2000,
        public readonly array $ignoreExceptions = [],
        public readonly array $scrubKeys = [],
        public readonly string $basePath = '',
        public readonly bool $enabled = true,
        public readonly string $sdkName = 'g8scope-php',
        public readonly string $sdkVersion = '1.2.0',
        public readonly int $maxRetries = 2,
        public readonly int $retryBaseMs = 100,
        public readonly int $retryCapMs = 2000,
        public readonly float $sampleRate = 1.0,
    ) {}

    /** @param array<string,mixed> $c */
    public static function fromArray(array $c): self
    {
        return new self(
            key: $c['key'] ?? null,
            endpoint: $c['endpoint'] ?? 'https://ingest.scope.g8suite.com',
            environment: $c['environment'] ?? 'production',
            release: $c['release'] ?? null,
            timeout: (int) ($c['timeout'] ?? 3),
            maxFrames: (int) ($c['max_frames'] ?? 30),
            maxMessage: (int) ($c['max_message'] ?? 2000),
            ignoreExceptions: $c['ignore_exceptions'] ?? [],
            scrubKeys: $c['scrub_keys'] ?? [],
            basePath: $c['base_path'] ?? '',
            enabled: (bool) ($c['enabled'] ?? true),
            sdkName: $c['sdk_name'] ?? 'g8scope-php',
            sdkVersion: $c['sdk_version'] ?? '1.2.0',
            maxRetries: (int) ($c['max_retries'] ?? 2),
            retryBaseMs: (int) ($c['retry_base_ms'] ?? 100),
            retryCapMs: (int) ($c['retry_cap_ms'] ?? 2000),
            // Client-side sampling, clamped to 0.0–1.0 (1.0 = send every event).
            sampleRate: max(0.0, min(1.0, (float) ($c['sample_rate'] ?? 1.0))),
        );
    }

    /** fromArray() keys read from the environment, by env name suffix (see Env). */
    public const ENV_KEYS = [
        'KEY' => 'key',
        'ENDPOINT' => 'endpoint',
        'ENABLED' => 'enabled',
        'ENVIRONMENT' => 'environment',
        'RELEASE' => 'release',
        'SAMPLE_RATE' => 'sample_rate',
        'TIMEOUT' => 'timeout',
        'MAX_RETRIES' => 'max_retries',
        'RETRY_BASE_MS' => 'retry_base_ms',
        'RETRY_CAP_MS' => 'retry_cap_ms',
        'MAX_FRAMES' => 'max_frames',
        'MAX_MESSAGE' => 'max_message',
    ];

    /**
     * Build from BLIPTRACE_* env (falling back to the deprecated G8SCOPE_*).
     * $c supplies defaults and non-env values (base_path, scrub_keys, ...);
     * a set env var wins over $c.
     *
     * @param  array<string,mixed>  $c
     */
    public static function fromEnv(array $c = []): self
    {
        foreach (self::ENV_KEYS as $name => $key) {
            $value = Env::get($name);
            if ($value !== null) {
                $c[$key] = $value;
            }
        }
        if (isset($c['enabled']) && is_string($c['enabled'])) {
            $c['enabled'] = filter_var($c['enabled'], FILTER_VALIDATE_BOOL);
        }

        return self::fromArray($c);
    }
}
