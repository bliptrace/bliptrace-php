# bliptrace/php

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bliptrace/php.svg?style=flat-square)](https://packagist.org/packages/bliptrace/php)

Runtime PHP SDK for [g8scope](https://scope.g8suite.com) — build, scrub and ship error envelopes to
the ingest API. **Framework-agnostic** (no Laravel/Symfony dependency). Framework SDKs like
[`bliptrace/laravel`](../bliptrace-laravel) are thin adapters on top of this.

## Install

```bash
composer require bliptrace/php
```

## Usage

```php
use G8Scope\Php\Client;
use G8Scope\Php\Config;

$g8 = new Client(new Config(
    key: 'your-ingest-key',
    endpoint: 'https://ingest.scope.g8suite.com',
    environment: 'production',
    release: 'my-app@1.0.0',
    basePath: __DIR__,                 // for in-app frame detection
    scrubKeys: ['password', 'token'],
    ignoreExceptions: [/* FQCNs to skip */],
    sampleRate: 1.0,                   // 0.0–1.0, share of events sent (per event, random)
));

try {
    // ...
} catch (\Throwable $e) {
    $g8->capture($e, ['user' => ['id' => '123']]);
}
```

- `capture()` builds + scrubs + ships immediately (guard-wrapped — never throws into your app).
- `buildEnvelope()` / `sendEnvelope()` let adapters defer the send (e.g. after an HTTP response).
- `sampleRate` (`sample_rate` in `Config::fromArray()`) drops a random share of events before the
  envelope is built; values outside 0.0–1.0 are clamped. The ingest API samples again server-side.
- Transport is pluggable (`Transport` interface); the default is dependency-free `CurlTransport`.

The envelope conforms to the `g8scope/contracts` schema; the project is resolved server-side from the
ingest key (never sent in the payload).

See [CHANGELOG](CHANGELOG.md) for release notes.

---

_Developers Hub Sdn Bhd · g8scope/php_
