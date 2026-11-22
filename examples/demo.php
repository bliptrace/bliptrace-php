<?php

declare(strict_types=1);

// Plain PHP (no framework) — capture with the runtime SDK directly.
require __DIR__.'/../vendor/autoload.php';

use G8Scope\Php\Client;
use G8Scope\Php\Config;

// BLIPTRACE_KEY / BLIPTRACE_ENDPOINT (or the deprecated G8SCOPE_*) override these.
$client = new Client(Config::fromEnv([
    'endpoint' => 'http://localhost:8088',
    'environment' => 'demo',
    'release' => 'demo-php@1.0',
    'base_path' => dirname(__DIR__),
]));

$fire = function (callable $fn) use ($client): void {
    try {
        $fn();
    } catch (Throwable $e) {
        $client->capture($e, ['demo' => true]);
        echo 'captured: '.$e::class.PHP_EOL;
    }
};

$fire(fn () => throw new RuntimeException('demo: runtime exception'));
$fire(fn () => intdiv(1, 0));                       // DivisionByZeroError (fatal)
$fire(fn () => strlen(...[]));                       // TypeError
$fire(fn () => json_decode('{', flags: JSON_THROW_ON_ERROR)); // JsonException
echo "done\n";
