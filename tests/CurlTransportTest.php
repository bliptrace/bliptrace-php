<?php

declare(strict_types=1);

use G8Scope\Php\Transport\CurlTransport;
use G8Scope\Php\Transport\RetryPolicy;

/**
 * Test double: scripts a sequence of [status, retryAfter] responses instead of
 * making real HTTP calls, and records the bodies seen + delays slept.
 */
final class ScriptedCurlTransport extends CurlTransport
{
    /** @var array<int,array{0:int,1:?int}> */
    public array $script;

    /** @var array<int,string> */
    public array $bodiesSeen = [];

    /** @var array<int,int> */
    public array $delays = [];

    public int $calls = 0;

    /** @param array<int,array{0:int,1:?int}> $script */
    public function __construct(array $script, RetryPolicy $policy)
    {
        parent::__construct($policy);
        $this->script = $script;
    }

    protected function attempt(string $url, string $body, string $key, int $timeout): array
    {
        $this->bodiesSeen[] = $body;
        $i = $this->calls++;

        return $this->script[$i] ?? [0, null];
    }

    protected function sleepMs(int $ms): void
    {
        $this->delays[] = $ms; // never actually sleep in tests
    }
}

$envelope = ['event_id' => 'fixed-id', 'exception' => ['type' => 'E']];

it('does not retry on a successful first attempt', function () use ($envelope): void {
    $t = new ScriptedCurlTransport([[202, null]], new RetryPolicy(maxRetries: 3));
    $t->send('http://api', 'k', $envelope, 1);

    expect($t->calls)->toBe(1)
        ->and($t->delays)->toBeEmpty();
});

it('retries on 5xx then stops on success, reusing the same body each attempt', function () use ($envelope): void {
    $t = new ScriptedCurlTransport([[503, null], [500, null], [202, null]], new RetryPolicy(maxRetries: 5));
    $t->send('http://api', 'k', $envelope, 1);

    expect($t->calls)->toBe(3)
        ->and($t->delays)->toHaveCount(2);

    // Idempotency depends on the event_id being identical across retries.
    expect($t->bodiesSeen)->toHaveCount(3)
        ->and($t->bodiesSeen[0])->toBe($t->bodiesSeen[1])
        ->and($t->bodiesSeen[1])->toBe($t->bodiesSeen[2])
        ->and($t->bodiesSeen[0])->toContain('fixed-id');
});

it('gives up after maxRetries on persistent failure', function () use ($envelope): void {
    $t = new ScriptedCurlTransport([[500, null], [500, null], [500, null], [500, null]], new RetryPolicy(maxRetries: 2));
    $t->send('http://api', 'k', $envelope, 1);

    expect($t->calls)->toBe(3) // 1 initial + 2 retries
        ->and($t->delays)->toHaveCount(2);
});

it('does not retry a non-retryable 4xx', function () use ($envelope): void {
    $t = new ScriptedCurlTransport([[400, null]], new RetryPolicy(maxRetries: 5));
    $t->send('http://api', 'k', $envelope, 1);

    expect($t->calls)->toBe(1)
        ->and($t->delays)->toBeEmpty();
});

it('honours a Retry-After header on 429', function () use ($envelope): void {
    $t = new ScriptedCurlTransport([[429, 2], [202, null]], new RetryPolicy(maxRetries: 3, capMs: 5000));
    $t->send('http://api', 'k', $envelope, 1);

    expect($t->calls)->toBe(2)
        ->and($t->delays)->toBe([2000]); // 2s from the header
});
