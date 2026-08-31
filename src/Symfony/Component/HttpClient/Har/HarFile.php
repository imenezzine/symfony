<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Har;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Recorder\Matcher\DefaultMatcher;
use Symfony\Component\HttpClient\Recorder\Matcher\MatcherInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @psalm-type HarEntry = array{
 *     startedDateTime: string,
 *     time: int,
 *     request: array{
 *         method: string,
 *         url: string,
 *         httpVersion: string,
 *         cookies: list<array>,
 *         headers: list<array{name: string, value: string}>,
 *         queryString: list<array>,
 *         postData: ?array{mimeType: string, text: string},
 *         headersSize: int,
 *         bodySize: int,
 *     },
 *     response: array{
 *         status: int,
 *         statusText: string,
 *         httpVersion: string,
 *         cookies: list<array>,
 *         headers: list<array{name: string, value: string}>,
 *         content: array{size: int, mimeType: string, text: string},
 *         redirectURL: string,
 *         headersSize: int,
 *         bodySize: int,
 *     },
 *     cache: array,
 *     timings: array{send: int, wait: int, receive: int},
 * }
 * @psalm-type HarLog = array{
 *     version: string,
 *     creator: array{name: string, version: string},
 *     entries: list<HarEntry>,
 * }
 * @psalm-type HarData = array{log: HarLog}
 */
final class HarFile
{
    /**
     * @psalm-param HarData $har
     */
    public function __construct(private array $har)
    {
    }

    public static function create(): self
    {
        return new self([
            'log' => [
                'version' => '1.2',
                'creator' => ['name' => self::class, 'version' => ''],
                'entries' => [],
            ],
        ]);
    }

    /**
     * @throws \InvalidArgumentException when the file does not exist
     * @throws \JsonException            when the file does not contain valid JSON
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('Invalid file path provided: "%s".', $path));
        }

        /** @psalm-var HarData $har */
        $har = json_decode(file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return new self($har);
    }

    /**
     * @throws TransportException when no entry matches
     */
    public function findResponse(string $method, string $url, array $options = [], ?MatcherInterface $matcher = null): ResponseInterface
    {
        $matcher ??= new DefaultMatcher();

        foreach ($this->har['log']['entries'] as $entry) {
            if (!$matcher->matches($entry, $method, $url, $options)) {
                continue;
            }

            $info = [
                'http_code' => $entry['response']['status'],
                'http_method' => $entry['request']['method'],
                'response_headers' => [],
                'start_time' => strtotime($entry['startedDateTime']),
                'url' => $entry['request']['url'],
            ];

            foreach ($entry['response']['headers'] as $header) {
                $info['response_headers'][$header['name']][] = $header['value'];
            }

            return new MockResponse(self::decodeContent($entry['response']['content']), $info);
        }

        throw new TransportException(\sprintf('No HAR entry found for "%s %s".', $method, $url));
    }

    /**
     * @param array<string, string[]> $requestHeaders
     * @param array<string, string[]> $responseHeaders
     */
    public function addEntry(MatcherInterface $matcher, string $method, string $url, ?string $requestBody, array $requestHeaders, int $status, array $responseHeaders, string $content): self
    {
        /** @psalm-var HarEntry $entry */
        $entry = [
            'startedDateTime' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'time' => 0,
            'request' => [
                'method' => $method,
                'url' => $url,
                'httpVersion' => 'HTTP/1.1',
                'cookies' => [],
                'headers' => self::formatHeaders($requestHeaders),
                'queryString' => [],
                'postData' => null !== $requestBody ? ['mimeType' => '', 'text' => $requestBody] : null,
                'headersSize' => -1,
                'bodySize' => null !== $requestBody ? \strlen($requestBody) : 0,
            ],
            'response' => [
                'status' => $status,
                'statusText' => '',
                'httpVersion' => 'HTTP/1.1',
                'cookies' => [],
                'headers' => self::formatHeaders($responseHeaders),
                'content' => [
                    'size' => \strlen($content),
                    'mimeType' => $responseHeaders['content-type'][0] ?? '',
                    'text' => $content,
                ],
                'redirectURL' => '',
                'headersSize' => -1,
                'bodySize' => \strlen($content),
            ],
            'cache' => [],
            'timings' => ['send' => 0, 'wait' => 0, 'receive' => 0],
        ];

        foreach ($this->har['log']['entries'] as $index => $existingEntry) {
            if ($matcher->matches($existingEntry, $method, $url, ['body' => $requestBody])) {
                $this->har['log']['entries'][$index] = $entry;

                return $this;
            }
        }

        $this->har['log']['entries'][] = $entry;

        return $this;
    }

    /**
     * @psalm-return HarData
     */
    public function toArray(): array
    {
        return $this->har;
    }

    /**
     * @param array<string, string[]> $headers
     *
     * @return list<array{name: string, value: string}>
     */
    private static function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                $formatted[] = ['name' => $name, 'value' => $value];
            }
        }

        return $formatted;
    }

    /**
     * @param array{text?: string, encoding?: string} $content
     */
    public static function decodeContent(array $content): string
    {
        $text = $content['text'] ?? '';
        $encoding = $content['encoding'] ?? null;

        return match ($encoding) {
            'base64' => base64_decode($text),
            null => $text,
            default => throw new \InvalidArgumentException(\sprintf('Unsupported encoding "%s", currently only base64 is supported.', $encoding)),
        };
    }
}
