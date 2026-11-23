<?php

declare(strict_types=1);

namespace G8Scope\Php;

/** Parses a W3C Trace Context `traceparent` header (version-traceid-parentid-flags). */
final class Traceparent
{
    /** @return array{trace_id:string,span_id:string}|null null when absent or malformed */
    public static function parse(?string $header): ?array
    {
        $header = strtolower(trim((string) $header));
        // Future versions may append fields after the flags ("-..."); version 00 may not.
        if (! preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}(-.*)?$/', $header, $m)) {
            return null;
        }
        // Version ff and all-zero ids are invalid per the spec.
        if ($m[1] === 'ff' || ($m[1] === '00' && isset($m[4])) || $m[2] === str_repeat('0', 32) || $m[3] === str_repeat('0', 16)) {
            return null;
        }

        return ['trace_id' => $m[2], 'span_id' => $m[3]];
    }
}
