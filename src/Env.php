<?php

declare(strict_types=1);

namespace Bliptrace\Php;

/**
 * Reads SDK environment variables: BLIPTRACE_<NAME> first, then the deprecated
 * G8SCOPE_<NAME> (removed in 3.0). Looks in $_ENV, $_SERVER, then getenv() so
 * dotenv loaders that skip putenv() still work.
 */
final class Env
{
    public const PREFIX = 'BLIPTRACE_';

    /** @deprecated since 1.2, removed in 3.0 — use BLIPTRACE_*. */
    public const LEGACY_PREFIX = 'G8SCOPE_';

    /** Value of BLIPTRACE_<name>, else G8SCOPE_<name>, else $default. */
    public static function get(string $name, ?string $default = null): ?string
    {
        return self::raw(self::PREFIX.$name) ?? self::raw(self::LEGACY_PREFIX.$name) ?? $default;
    }

    private static function raw(string $var): ?string
    {
        $value = $_ENV[$var] ?? $_SERVER[$var] ?? getenv($var);

        return is_scalar($value) && $value !== false ? (string) $value : null;
    }
}
