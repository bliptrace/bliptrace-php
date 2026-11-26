<?php

declare(strict_types=1);

namespace Bliptrace\Php;

use Bliptrace\Php\Transport\CurlTransport;
use Bliptrace\Php\Transport\RetryPolicy;
use Bliptrace\Php\Transport\Transport;
use Throwable;

/**
 * The runtime PHP client: decide whether to capture, build + scrub the envelope,
 * and ship it. Framework adapters either call capture() directly or split
 * buildEnvelope()/sendEnvelope() to defer the send (e.g. after the HTTP response).
 */
final class Client
{
    private Transport $transport;

    private Scrubber $scrubber;

    /** @var list<array<string,mixed>> Oldest first, bounded by Config::$maxBreadcrumbs. */
    private array $breadcrumbs = [];

    /** @var array<string,string> */
    private array $tags = [];

    /** @var array{trace_id?:string,span_id?:string} */
    private array $trace = [];

    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?Scrubber $scrubber = null,
    ) {
        $this->transport = $transport ?? new CurlTransport(RetryPolicy::fromConfig($config));
        $this->scrubber = $scrubber ?? new Scrubber($config->scrubKeys);
        $this->setTags($config->tags);
    }

    /**
     * Record a breadcrumb (oldest dropped past max_breadcrumbs). Data is scrubbed
     * when an envelope is built, not here.
     *
     * @param  array<string,mixed>  $data
     */
    public function addBreadcrumb(
        string $type,
        ?string $message = null,
        array $data = [],
        ?string $category = null,
        string $level = 'info',
    ): void {
        if (! $this->config->enabled || $this->config->maxBreadcrumbs < 1) {
            return;
        }

        $crumb = [
            'timestamp' => Envelope::timestamp(),
            'type' => $type,
            'level' => in_array($level, Envelope::LEVELS, true) ? $level : 'info',
        ];
        if ($category !== null) {
            $crumb['category'] = $category;
        }
        if ($message !== null) {
            $crumb['message'] = mb_substr($message, 0, $this->config->maxMessage);
        }
        if ($data !== []) {
            $crumb['data'] = $data;
        }

        $this->breadcrumbs[] = $crumb;
        if (count($this->breadcrumbs) > $this->config->maxBreadcrumbs) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, -$this->config->maxBreadcrumbs);
        }
    }

    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs = [];
    }

    /** @return list<array<string,mixed>> Unscrubbed buffer, oldest first. */
    public function breadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    public function setTag(string $key, string|int|float|bool $value): void
    {
        $this->tags[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @param array<string,string|int|float|bool> $tags */
    public function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            if (is_scalar($value)) {
                $this->setTag((string) $key, $value);
            }
        }
    }

    /** @return array<string,string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** Set (or with null, clear) the W3C trace/span ids attached to the next envelope. */
    public function setTrace(?string $traceId, ?string $spanId = null): void
    {
        $this->trace = array_filter(['trace_id' => $traceId, 'span_id' => $spanId], fn ($v) => $v !== null && $v !== '');
    }

    /** Set the trace from a W3C `traceparent` header. Returns false (and changes nothing) when it is malformed. */
    public function setTraceparent(?string $header): bool
    {
        $parsed = Traceparent::parse($header);
        if ($parsed === null) {
            return false;
        }
        $this->setTrace($parsed['trace_id'], $parsed['span_id']);

        return true;
    }

    /** @return array{trace_id?:string,span_id?:string} */
    public function trace(): array
    {
        return $this->trace;
    }

    /** Capture and ship immediately. @param array<string,mixed> $context */
    public function capture(Throwable $e, array $context = []): void
    {
        $envelope = $this->buildEnvelope($e, $context);
        if ($envelope !== null) {
            $this->sendEnvelope($envelope);
        }
    }

    /**
     * Build the envelope (scrubbing context), or null when capture is disabled,
     * unconfigured, or the exception is ignored. Lets adapters defer the send.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    public function buildEnvelope(Throwable $e, array $context = []): ?array
    {
        try {
            if (! $this->config->enabled || ! $this->config->key) {
                return null;
            }
            if ($this->shouldIgnore($e) || ! $this->sampled()) {
                return null;
            }

            return Envelope::build($e, $this->config, $this->scrubber->scrub($context), $this->scope());
        } catch (Throwable) {
            return null; // never break the app
        }
    }

    /** @param array<string,mixed> $envelope */
    public function sendEnvelope(array $envelope): void
    {
        if ($this->config->key) {
            $this->transport->send($this->config->endpoint, $this->config->key, $envelope, $this->config->timeout);
        }
    }

    /**
     * Breadcrumbs, tags and trace for the envelope — scrubbed with the same keys as context.
     *
     * @return array<string,mixed>
     */
    private function scope(): array
    {
        $breadcrumbs = array_map(function (array $crumb): array {
            if (isset($crumb['data'])) {
                $crumb['data'] = $this->scrubber->scrub($crumb['data']);
            }

            return $crumb;
        }, $this->breadcrumbs);

        return array_filter([
            'breadcrumbs' => $breadcrumbs,
            'tags' => $this->scrubber->scrub($this->tags),
            'trace' => $this->trace,
        ]);
    }

    /** Per-event random sampling; the server samples again on its side. */
    private function sampled(): bool
    {
        $rate = $this->config->sampleRate;

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }

    private function shouldIgnore(Throwable $e): bool
    {
        foreach ($this->config->ignoreExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
