<?php

declare(strict_types=1);

namespace G8Scope\Php;

/** Client-side PII scrub: redact sensitive keys before sending. */
final class Scrubber
{
    /** @param array<int,string> $keys */
    public function __construct(private readonly array $keys = []) {}

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function scrub(array $data): array
    {
        $redact = array_map('strtolower', $this->keys);

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $redact, true)) {
                $data[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }
}
