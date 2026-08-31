<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Recorder\Matcher\DefaultMatcher;
use Symfony\Component\HttpClient\Recorder\Matcher\MatcherInterface;
use Symfony\Component\HttpClient\Recorder\RecorderRegistry;
use Symfony\Component\HttpClient\Recorder\Redactor\DefaultRedactor;
use Symfony\Component\HttpClient\Recorder\Redactor\RedactorInterface;
use Symfony\Component\HttpClient\Recorder\Store\StoreInterface;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\HttpClient\Test\HarFileResponseFactory;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RecorderHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    /**
     * @psalm-var RecorderMode::*|string
     */
    private string $mode = RecorderMode::PASSTHROUGH;
    private string $harFilePath = 'default.har';
    private bool $recordIfMissing = false;

    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly StoreInterface $store,
        private readonly MatcherInterface $matcher = new DefaultMatcher(),
        private readonly RedactorInterface $redactor = new DefaultRedactor(),
    ) {
        $this->client = $inner;

        RecorderRegistry::register($this);
    }

    /**
     * @psalm-param RecorderMode::*|string $mode
     */
    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function setHarFilePath(string $harFilePath): void
    {
        $this->harFilePath = $harFilePath;
    }

    /**
     * When true, a REPLAY miss falls back to a real request and records it,
     * instead of throwing. Opt-in: a miss must never silently reach the
     * network unless explicitly allowed.
     */
    public function setRecordIfMissing(bool $recordIfMissing): void
    {
        $this->recordIfMissing = $recordIfMissing;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (RecorderMode::PASSTHROUGH === $this->mode) {
            return new AsyncResponse($this->client, $method, $url, $options);
        }

        if (RecorderMode::REPLAY === $this->mode) {
            try {
                return $this->replay($method, $url, $options);
            } catch (TransportException $e) {
                if (!$this->recordIfMissing) {
                    throw $e;
                }

                return $this->record($method, $url, $options);
            }
        }

        if (RecorderMode::RECORD === $this->mode) {
            return $this->record($method, $url, $options);
        }

        throw new \RuntimeException('Unknown recorder mode.');
    }

    /**
     * @throws TransportException when no matching entry is found, without ever reaching the network
     */
    private function replay(string $method, string $url, array $options): ResponseInterface
    {
        if (!is_file($this->harFilePath)) {
            throw new TransportException(\sprintf('No HAR file found at "%s".', $this->harFilePath));
        }

        $redactedUrl = $this->redactor->redactUrl($url);

        if (isset($options['body']) && \is_string($options['body'])) {
            $options['body'] = $this->redactor->redactBody($options['body']);
        }

        $factory = new HarFileResponseFactory($this->harFilePath, $this->matcher);

        return new AsyncResponse(new MockHttpClient($factory), $method, $redactedUrl, $options);
    }

    private function record(string $method, string $url, array $options): ResponseInterface
    {
        $matchUrl = $this->redactor->redactUrl($url);
        $requestBody = isset($options['body']) && \is_string($options['body']) ? $this->redactor->redactBody($options['body']) : null;
        $requestHeaders = $this->redactor->redactHeaders(self::normalizeHeaders($options['headers'] ?? []));

        $buffer = '';

        return new AsyncResponse($this->client, $method, $url, $options, function (ChunkInterface $chunk, AsyncContext $context) use (&$buffer, $method, $matchUrl, $requestBody, $requestHeaders): \Generator {
            if (null !== $chunk->getError()) {
                yield $chunk;

                return;
            }

            if (!$chunk->isFirst() && !$chunk->isLast()) {
                $buffer .= $chunk->getContent();
            }

            if ($chunk->isLast()) {
                $this->persist($method, $matchUrl, $requestBody, $requestHeaders, $context->getStatusCode(), $context->getHeaders(), $buffer);
            }

            yield $chunk;
        });
    }

    /**
     * @param array<string, string[]> $requestHeaders
     * @param array<string, string[]> $responseHeaders
     */
    private function persist(string $method, string $url, ?string $requestBody, array $requestHeaders, int $status, array $responseHeaders, string $content): void
    {
        $har = $this->store->load($this->harFilePath);

        $har->addEntry(
            $this->matcher,
            $method,
            $url,
            $requestBody,
            $requestHeaders,
            $status,
            $this->redactor->redactHeaders($responseHeaders),
            $content,
        );

        $this->store->save($this->harFilePath, $har);
    }

    /**
     * @return array<string, string[]>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            if (\is_int($name)) {
                [$name, $value] = explode(':', (string) $values, 2);
                $values = [ltrim($value)];
            } else {
                $values = array_map('strval', (array) $values);
            }

            $normalized[strtolower($name)] = $values;
        }

        return $normalized;
    }
}
