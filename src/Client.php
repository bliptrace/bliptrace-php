<?php

declare(strict_types=1);

namespace G8Scope\Php;

use G8Scope\Php\Transport\CurlTransport;
use G8Scope\Php\Transport\RetryPolicy;
use G8Scope\Php\Transport\Transport;
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

    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        ?Scrubber $scrubber = null,
    ) {
        $this->transport = $transport ?? new CurlTransport(RetryPolicy::fromConfig($config));
        $this->scrubber = $scrubber ?? new Scrubber($config->scrubKeys);
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

            return Envelope::build($e, $this->config, $this->scrubber->scrub($context));
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
