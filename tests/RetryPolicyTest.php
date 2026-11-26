<?php

declare(strict_types=1);

use Bliptrace\Php\Config;
use Bliptrace\Php\Transport\RetryPolicy;

it('retries transport errors, 408, 429 and 5xx, but not success or other 4xx', function (int $status, bool $expected): void {
    $policy = new RetryPolicy(maxRetries: 5);
    expect($policy->isRetryable($status))->toBe($expected);
})->with([
    'transport error / timeout' => [0, true],
    'request timeout 408' => [408, true],
    'too many requests 429' => [429, true],
    'bad gateway 502' => [502, true],
    'service unavailable 503' => [503, true],
    'ok 200' => [200, false],
    'accepted 202' => [202, false],
    'bad request 400' => [400, false],
    'forbidden 403' => [403, false],
]);

it('stops retrying once the cap on attempts is reached', function (): void {
    $policy = new RetryPolicy(maxRetries: 2);
    expect($policy->shouldRetry(503, 0))->toBeTrue()
        ->and($policy->shouldRetry(503, 1))->toBeTrue()
        ->and($policy->shouldRetry(503, 2))->toBeFalse(); // exhausted
});

it('grows the delay exponentially and never exceeds the cap', function (): void {
    $policy = new RetryPolicy(baseMs: 100, capMs: 2000);
    $noJitter = fn (): float => 0.5; // 2*0.5-1 = 0 → no jitter

    expect($policy->delayMs(0, null, $noJitter))->toBe(100)
        ->and($policy->delayMs(1, null, $noJitter))->toBe(200)
        ->and($policy->delayMs(2, null, $noJitter))->toBe(400)
        ->and($policy->delayMs(8, null, $noJitter))->toBe(2000); // capped
});

it('keeps jittered delay within ±25% of the exponential base', function (): void {
    $policy = new RetryPolicy(baseMs: 100, capMs: 100000);

    foreach ([fn () => 0.0, fn () => 0.999, fn () => 0.5] as $jitter) {
        $d = $policy->delayMs(3, null, $jitter); // base 800ms
        expect($d)->toBeGreaterThanOrEqual(600)->toBeLessThanOrEqual(1000);
    }
});

it('honours a server Retry-After (seconds), capped', function (): void {
    $policy = new RetryPolicy(capMs: 5000);
    expect($policy->delayMs(0, 2))->toBe(2000)       // 2s honoured
        ->and($policy->delayMs(0, 999))->toBe(5000); // hostile value capped
});

it('builds from SDK config', function (): void {
    $policy = RetryPolicy::fromConfig(new Config(key: 'k', maxRetries: 7, retryBaseMs: 50, retryCapMs: 9000));
    expect($policy->maxRetries)->toBe(7)
        ->and($policy->baseMs)->toBe(50)
        ->and($policy->capMs)->toBe(9000);
});
