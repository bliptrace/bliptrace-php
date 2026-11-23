<?php

declare(strict_types=1);

use G8Scope\Php\Client;
use G8Scope\Php\Config;
use G8Scope\Php\Traceparent;
use G8Scope\Php\Transport\CurlTransport;

function scopedClient(FakeTransport $t, array $config = []): Client
{
    return new Client(Config::fromArray(['key' => 'k', 'scrub_keys' => ['password', 'token']] + $config), $t);
}

it('keeps only the newest max_breadcrumbs, oldest first', function (): void {
    $t = new FakeTransport;
    $client = scopedClient($t, ['max_breadcrumbs' => 3]);
    foreach (range(1, 5) as $i) {
        $client->addBreadcrumb('log', "crumb {$i}");
    }
    $client->capture(new RuntimeException('boom'));

    $crumbs = $t->sent[0]['envelope']['breadcrumbs'];
    expect(array_column($crumbs, 'message'))->toBe(['crumb 3', 'crumb 4', 'crumb 5'])
        ->and($crumbs[0])->toHaveKeys(['timestamp', 'type', 'level'])
        ->and($crumbs[0]['timestamp'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/');
});

it('defaults max_breadcrumbs to 50 and 0 disables them', function (): void {
    $t = new FakeTransport;
    $client = scopedClient($t);
    foreach (range(1, 60) as $i) {
        $client->addBreadcrumb('log', "c{$i}");
    }
    expect($client->breadcrumbs())->toHaveCount(50);

    $off = scopedClient($t, ['max_breadcrumbs' => 0]);
    $off->addBreadcrumb('log', 'x');
    expect($off->breadcrumbs())->toBe([]);
});

it('scrubs breadcrumb data and tags before send, keeping the buffer intact', function (): void {
    $t = new FakeTransport;
    $client = scopedClient($t);
    $client->addBreadcrumb('http', 'POST /login', ['user' => 'a', 'password' => 'hunter2', 'nested' => ['token' => 'abc']], 'request');
    $client->setTag('token', 'secret-value');
    $client->capture(new RuntimeException('boom'));

    $env = $t->sent[0]['envelope'];
    expect($env['breadcrumbs'][0]['data'])->toBe(['user' => 'a', 'password' => '[REDACTED]', 'nested' => ['token' => '[REDACTED]']])
        ->and($env['breadcrumbs'][0]['category'])->toBe('request')
        ->and($env['tags']['token'])->toBe('[REDACTED]')
        ->and($client->breadcrumbs()[0]['data']['password'])->toBe('hunter2');
});

it('normalises an unknown breadcrumb level to info', function (): void {
    $client = scopedClient(new FakeTransport);
    $client->addBreadcrumb('log', 'x', level: 'notice');
    expect($client->breadcrumbs()[0]['level'])->toBe('info');
});

it('sends tags as strings, merging config tags with setTag/setTags', function (): void {
    $t = new FakeTransport;
    $client = scopedClient($t, ['tags' => ['tenant' => 'umpsa']]);
    $client->setTag('region', 'my');
    $client->setTags(['shard' => 3, 'beta' => true]);
    $client->capture(new RuntimeException('boom'));

    expect($t->sent[0]['envelope']['tags'])->toBe(['tenant' => 'umpsa', 'region' => 'my', 'shard' => '3', 'beta' => 'true']);
});

it('omits breadcrumbs, tags and trace when none are set', function (): void {
    $t = new FakeTransport;
    scopedClient($t)->capture(new RuntimeException('boom'));

    expect($t->sent[0]['envelope'])->not->toHaveKeys(['breadcrumbs', 'tags', 'trace']);
});

it('passes trace ids through, from setTrace or a traceparent header', function (): void {
    $t = new FakeTransport;
    $client = scopedClient($t);
    $client->setTrace('abc123');
    $client->capture(new RuntimeException('one'));
    expect($t->sent[0]['envelope']['trace'])->toBe(['trace_id' => 'abc123']);

    expect($client->setTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'))->toBeTrue();
    $client->capture(new RuntimeException('two'));
    expect($t->sent[1]['envelope']['trace'])->toBe(['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => '00f067aa0ba902b7']);

    expect($client->setTraceparent('garbage'))->toBeFalse()
        ->and($client->trace()['trace_id'])->toBe('4bf92f3577b34da6a3ce929d0e0e4736'); // unchanged

    $client->setTrace(null);
    expect($client->trace())->toBe([]);
});

it('parses W3C traceparent headers', function (?string $header, ?array $expected): void {
    expect(Traceparent::parse($header))->toBe($expected);
})->with([
    'valid' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => '00f067aa0ba902b7']],
    'uppercase + spaces' => [' 00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-00 ', ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => '00f067aa0ba902b7']],
    'future version extra field' => ['01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-xyz', ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => '00f067aa0ba902b7']],
    'v00 extra field' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-xyz', null],
    'version ff' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', null],
    'zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01', null],
    'zero span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01', null],
    'short trace id' => ['00-4bf92f35-00f067aa0ba902b7-01', null],
    'empty' => ['', null],
    'null' => [null, null],
]);

it('records nothing while disabled', function (): void {
    $client = scopedClient(new FakeTransport, ['enabled' => false]);
    $client->addBreadcrumb('log', 'x');
    expect($client->breadcrumbs())->toBe([]);
});

it('posts to the full ingest URL whether endpoint is the URL or a base', function (string $endpoint): void {
    expect(CurlTransport::url($endpoint))->toBe('https://ingest.bliptrace.com/v1/ingest');
})->with([
    'default' => [Config::DEFAULT_ENDPOINT],
    'trailing slash' => ['https://ingest.bliptrace.com/v1/ingest/'],
    'base url' => ['https://ingest.bliptrace.com'],
    'base url slash' => ['https://ingest.bliptrace.com/'],
]);
