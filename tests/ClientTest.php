<?php

declare(strict_types=1);

use G8Scope\Php\Client;
use G8Scope\Php\Config;
use G8Scope\Php\Transport\Transport;

final class FakeTransport implements Transport
{
    /** @var array<int,array<string,mixed>> */
    public array $sent = [];

    public function send(string $endpoint, string $key, array $envelope, int $timeout): void
    {
        $this->sent[] = compact('endpoint', 'key', 'envelope', 'timeout');
    }
}

it('builds and ships a valid envelope', function (): void {
    $t = new FakeTransport;
    (new Client(new Config(key: 'k', endpoint: 'http://api', basePath: '/srv/app'), $t))
        ->capture(new RuntimeException('boom'));

    expect($t->sent)->toHaveCount(1);
    $env = $t->sent[0]['envelope'];
    expect($env['schema_version'])->toBe('1.1')
        ->and($env['event_id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($env['runtime']['name'])->toBe('php')
        ->and($env['sdk']['name'])->toBe('g8scope-php')
        ->and($env['level'])->toBe('error')
        ->and($env['exception']['type'])->toBe('RuntimeException')
        ->and($env['exception']['message'])->toBe('boom')
        ->and($env['exception']['frames'][0])->toHaveKeys(['file', 'line', 'function', 'in_app']);
});

it('captures the chained cause as exception.previous (1.1)', function (): void {
    $t = new FakeTransport;
    $e = new RuntimeException('outer', 0, new LogicException('root cause'));
    (new Client(new Config(key: 'k'), $t))->capture($e);

    $env = $t->sent[0]['envelope'];
    expect($env['exception']['previous'])->toHaveCount(1)
        ->and($env['exception']['previous'][0]['type'])->toBe('LogicException')
        ->and($env['exception']['previous'][0]['message'])->toBe('root cause');
});

it('attaches source code context to in-app frames (1.1)', function (): void {
    $t = new FakeTransport;
    // basePath = this test dir so the throwing file counts as in-app.
    (new Client(new Config(key: 'k', basePath: __DIR__), $t))
        ->capture(new RuntimeException('boom'));

    $frame = $t->sent[0]['envelope']['exception']['frames'][0];
    expect($frame['in_app'])->toBeTrue()
        ->and($frame)->toHaveKey('context')
        ->and($frame['context'])->toHaveKeys(['pre', 'line', 'post'])
        ->and($frame['context']['line'])->toContain('RuntimeException');
});

it('marks an Error as fatal', function (): void {
    $t = new FakeTransport;
    (new Client(new Config(key: 'k'), $t))->capture(new Error('segfault-ish'));
    expect($t->sent[0]['envelope']['level'])->toBe('fatal');
});

it('ignores configured exception types', function (): void {
    $t = new FakeTransport;
    (new Client(new Config(key: 'k', ignoreExceptions: [LogicException::class]), $t))
        ->capture(new LogicException('noise'));
    expect($t->sent)->toBeEmpty();
});

it('does nothing without a key or when disabled', function (): void {
    $t = new FakeTransport;
    (new Client(new Config(key: null), $t))->capture(new Exception('x'));
    (new Client(new Config(key: 'k', enabled: false), $t))->capture(new Exception('x'));
    expect($t->sent)->toBeEmpty();
});

it('scrubs sensitive context before sending', function (): void {
    $t = new FakeTransport;
    (new Client(new Config(key: 'k', scrubKeys: ['password']), $t))
        ->capture(new Exception('x'), ['user' => ['password' => 'hunter2', 'id' => '7']]);

    $ctx = $t->sent[0]['envelope']['context'];
    expect($ctx['user']['password'])->toBe('[REDACTED]')
        ->and($ctx['user']['id'])->toBe('7');
});

it('never throws even if context is weird', function (): void {
    $t = new FakeTransport;
    $client = new Client(new Config(key: 'k'), $t);
    expect(fn () => $client->capture(new Exception('x'), ['a' => fn () => 1]))->not->toThrow(Throwable::class);
});
