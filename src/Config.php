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
        public readonly string $sdkVersion = '1.0.0',
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
            sdkVersion: $c['sdk_version'] ?? '1.0.0',
        );
    }
}
