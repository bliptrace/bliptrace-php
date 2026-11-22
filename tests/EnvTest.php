<?php

declare(strict_types=1);

use G8Scope\Php\Config;
use G8Scope\Php\Env;

/** Set (string) or unset (null) an env var everywhere Env looks. */
function setEnvVar(string $var, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$var], $_SERVER[$var]);
        putenv($var);

        return;
    }
    $_ENV[$var] = $value;
    putenv("{$var}={$value}");
}

afterEach(function (): void {
    foreach (array_keys(Config::ENV_KEYS) as $name) {
        setEnvVar('BLIPTRACE_'.$name, null);
        setEnvVar('G8SCOPE_'.$name, null);
    }
});

it('resolves BLIPTRACE_* first, then G8SCOPE_*, then the default', function (?string $new, ?string $old, ?string $expected): void {
    setEnvVar('BLIPTRACE_KEY', $new);
    setEnvVar('G8SCOPE_KEY', $old);

    expect(Env::get('KEY', 'default'))->toBe($expected);
})->with([
    'new wins over old' => ['new', 'old', 'new'],
    'old when new unset' => [null, 'old', 'old'],
    'new alone' => ['new', null, 'new'],
    'both unset' => [null, null, 'default'],
]);

it('reads vars only present in getenv() (putenv) and in $_SERVER', function (): void {
    putenv('BLIPTRACE_ENDPOINT=http://putenv');
    $_SERVER['G8SCOPE_RELEASE'] = 'srv@1';

    expect(Env::get('ENDPOINT'))->toBe('http://putenv')
        ->and(Env::get('RELEASE'))->toBe('srv@1');
});

it('builds Config from new names, old names and defaults', function (): void {
    setEnvVar('BLIPTRACE_KEY', 'new-key');
    setEnvVar('G8SCOPE_KEY', 'old-key');
    setEnvVar('G8SCOPE_ENDPOINT', 'http://old-endpoint');   // new unset → old still works
    setEnvVar('BLIPTRACE_ENABLED', 'false');
    setEnvVar('BLIPTRACE_SAMPLE_RATE', '0.5');
    setEnvVar('G8SCOPE_MAX_RETRIES', '5');

    $c = Config::fromEnv(['base_path' => '/srv', 'release' => 'from-array']);

    expect($c->key)->toBe('new-key')
        ->and($c->endpoint)->toBe('http://old-endpoint')
        ->and($c->enabled)->toBeFalse()
        ->and($c->sampleRate)->toBe(0.5)
        ->and($c->maxRetries)->toBe(5)
        ->and($c->release)->toBe('from-array')               // unset env keeps the array value
        ->and($c->basePath)->toBe('/srv');
});

it('falls back to the built-in defaults when no env var is set', function (): void {
    $c = Config::fromEnv();

    expect($c->key)->toBeNull()
        ->and($c->endpoint)->toBe('https://ingest.scope.g8suite.com')
        ->and($c->enabled)->toBeTrue()
        ->and($c->timeout)->toBe(3)
        ->and($c->sdkVersion)->toBe('1.2.0');
});
