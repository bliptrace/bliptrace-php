<?php

declare(strict_types=1);

namespace G8Scope\Php;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Builds the language-neutral g8scope error envelope from a Throwable. */
final class Envelope
{
    /** Envelope / breadcrumb `level` enum. */
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'fatal'];

    /**
     * @param  array<string,mixed>  $context  Already-scrubbed context (request/user/custom).
     * @param  array{breadcrumbs?:list<array<string,mixed>>,tags?:array<string,string>,trace?:array<string,string>}  $scope  Already-scrubbed; empty keys are omitted.
     * @return array<string,mixed>
     */
    public static function build(Throwable $e, Config $config, array $context = [], array $scope = []): array
    {
        $envelope = [
            'schema_version' => '1.1',
            'event_id' => self::uuid4(),
            'occurred_at' => self::timestamp(),
            'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
            'sdk' => ['name' => $config->sdkName, 'version' => $config->sdkVersion],
            'environment' => $config->environment,
            'release' => $config->release,
            'level' => $e instanceof \Error ? 'fatal' : 'error',
            'exception' => self::exception($e, $config),
            'context' => self::context($context),
        ];

        foreach (['breadcrumbs', 'tags', 'trace'] as $key) {
            if (! empty($scope[$key])) {
                $envelope[$key] = $scope[$key];
            }
        }

        return $envelope;
    }

    /** RFC 3339 UTC timestamp with milliseconds, as used for occurred_at and breadcrumbs. */
    public static function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * The exception block, including the chained-cause chain (envelope 1.1).
     *
     * @return array<string,mixed>
     */
    private static function exception(Throwable $e, Config $config): array
    {
        $exception = [
            'type' => $e::class,
            'message' => self::limit((string) $e->getMessage(), $config->maxMessage),
            'frames' => self::frames($e, $config),
        ];

        $previous = [];
        $cause = $e->getPrevious();
        $depth = 0;
        while ($cause !== null && $depth < 5) {
            $previous[] = [
                'type' => $cause::class,
                'message' => self::limit((string) $cause->getMessage(), $config->maxMessage),
            ];
            $cause = $cause->getPrevious();
            $depth++;
        }

        if ($previous !== []) {
            $exception['previous'] = $previous;
        }

        return $exception;
    }

    /**
     * Normalise context so empty map fields encode as JSON objects, not arrays —
     * an empty PHP array becomes `[]` in JSON, but the schema wants `{}`.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private static function context(array $context): array
    {
        $context['custom'] = empty($context['custom']) ? (object) [] : $context['custom'];

        if (isset($context['request']['headers']) && $context['request']['headers'] === []) {
            $context['request']['headers'] = (object) [];
        }

        return $context;
    }

    /** Source lines to capture either side of a frame's line (envelope 1.1 code context). */
    private const CONTEXT_LINES = 5;

    /** @return array<int,array<string,mixed>> */
    private static function frames(Throwable $e, Config $config): array
    {
        $frames = [self::frame($e->getFile(), $e->getLine(), '__throw', $config)];

        foreach (array_slice($e->getTrace(), 0, $config->maxFrames) as $t) {
            $frames[] = self::frame(
                $t['file'] ?? '[internal]',
                (int) ($t['line'] ?? 0),
                ($t['class'] ?? '').($t['type'] ?? '').($t['function'] ?? ''),
                $config,
            );
        }

        return array_slice($frames, 0, $config->maxFrames);
    }

    /** @return array<string,mixed> */
    private static function frame(string $file, int $line, string $function, Config $config): array
    {
        $inApp = self::inApp($file, $config->basePath);

        $frame = [
            'file' => $file,
            'line' => $line,
            'function' => $function,
            'in_app' => $inApp,
        ];

        // Code context for in-app frames only (bounds IO + avoids vendor noise).
        if ($inApp && ($context = self::frameContext($file, $line)) !== null) {
            $frame['context'] = $context;
        }

        return $frame;
    }

    /**
     * Read the source lines surrounding a frame's line, for the issue-detail
     * code-context view. Returns null when the file/line can't be read.
     *
     * @return array{pre:list<string>,line:string,post:list<string>}|null
     */
    private static function frameContext(string $file, int $line): ?array
    {
        if ($line < 1 || $file === '' || $file[0] === '[' || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $index = $line - 1;
        if (! isset($lines[$index])) {
            return null;
        }

        $pre = [];
        for ($i = max(0, $index - self::CONTEXT_LINES); $i < $index; $i++) {
            $pre[] = $lines[$i];
        }

        $post = [];
        $last = min(count($lines) - 1, $index + self::CONTEXT_LINES);
        for ($i = $index + 1; $i <= $last; $i++) {
            $post[] = $lines[$i];
        }

        return ['pre' => $pre, 'line' => $lines[$index], 'post' => $post];
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
        $d[6] = chr((ord($d[6]) & 0x0F) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
