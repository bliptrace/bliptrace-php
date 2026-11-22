# Changelog

All notable changes to `bliptrace/php` (formerly `g8scope/php`) are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - 2026-11-23

### Added
- `G8Scope\Php\Env::get()` reads `BLIPTRACE_<NAME>`, falling back to `G8SCOPE_<NAME>`.
- `Config::fromEnv(array $defaults = [])` builds a config from the `BLIPTRACE_*` variables
  (`KEY`, `ENDPOINT`, `ENABLED`, `ENVIRONMENT`, `RELEASE`, `SAMPLE_RATE`, `TIMEOUT`, `MAX_RETRIES`,
  `RETRY_BASE_MS`, `RETRY_CAP_MS`, `MAX_FRAMES`, `MAX_MESSAGE`).

### Changed
- Default `sdkVersion` reported in the envelope is now `1.2.0`. `sdk.name` (`g8scope-php`) and the
  PHP namespace are unchanged.

### Deprecated
- `G8SCOPE_*` environment variable names. They are still read when the `BLIPTRACE_*` name is unset;
  the fallback will be removed in 2.0.

## [1.1.0] - 2026-11-23

### Added
- Client-side sampling: `Config::$sampleRate` / `sample_rate` (0.0–1.0, default 1.0, clamped).
  Each event is kept or dropped at random before the envelope is built.

### Changed
- **Package renamed `g8scope/php` → `bliptrace/php`.** Run
  `composer remove g8scope/php && composer require bliptrace/php:^1.1`. The PHP namespace
  (`G8Scope\Php`), config keys and the envelope's `sdk.name` (`g8scope-php`) are unchanged.
- Default `sdkVersion` reported in the envelope is now `1.1.0`.

## [1.0.0] - 2026-07-15

### Added
- Initial release as `g8scope/php`: envelope 1.1 builder, scrubber, cURL transport with retry + backoff, client.

[Unreleased]: https://github.com/bliptrace/bliptrace-php/compare/1.2.0...HEAD
[1.2.0]: https://github.com/bliptrace/bliptrace-php/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/bliptrace/bliptrace-php/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/bliptrace/bliptrace-php/releases/tag/1.0.0
