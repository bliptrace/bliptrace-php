<?php

declare(strict_types=1);

namespace G8Scope\Php;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Builds the language-neutral g8scope error envelope from a Throwable. */
final class Envelope
{
    /**
     * @param  array<string,mixed>  $context  Already-scrubbed context (request/user/custom).
     * @return array<string,mixed>
     */
    public static function build(Throwable $e, Config $config, array $context = []): array
    {
        return [
            'schema_version' => '1.0',
            'event_id' => self::uuid4(),
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
            'sdk' => ['name' => $config->sdkName, 'version' => $config->sdkVersion],
            'environment' => $config->environment,
            'release' => $config->release,
            'level' => $e instanceof \Error ? 'fatal' : 'error',
            'exception' => [
                'type' => $e::class,
                'message' => self::limit((string) $e->getMessage(), $config->maxMessage),
                'frames' => self::frames($e, $config),
            ],
            'context' => $context ?: ['custom' => []],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function frames(Throwable $e, Config $config): array
    {
        $frames = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'function' => '__throw',
            'in_app' => self::inApp($e->getFile(), $config->basePath),
        ]];

        foreach (array_slice($e->getTrace(), 0, $config->maxFrames) as $t) {
            $file = $t['file'] ?? '[internal]';
            $frames[] = [
                'file' => $file,
                'line' => $t['line'] ?? 0,
                'function' => ($t['class'] ?? '').($t['type'] ?? '').($t['function'] ?? ''),
                'in_app' => self::inApp($file, $config->basePath),
            ];
        }

        return array_slice($frames, 0, $config->maxFrames);
    }

    private static function inApp(string $file, string $basePath): bool
    {
        return $basePath !== '' && str_starts_with($file, $basePath) && ! str_contains($file, '/vendor/');
    }

    private static function limit(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    private static function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
