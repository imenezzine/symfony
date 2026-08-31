<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Recorder\RecorderRegistry;
use Symfony\Component\HttpClient\Recorder\Store\FilesystemStore;
use Symfony\Component\HttpClient\RecorderHttpClient;
use Symfony\Component\HttpClient\RecorderMode;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class RecorderHttpClientTest extends TestCase
{
    private string $harFile;

    protected function setUp(): void
    {
        $this->harFile = sys_get_temp_dir().'/'.uniqid('recorder_http_client_test_', true).'.har';
    }

    protected function tearDown(): void
    {
        @unlink($this->harFile);
    }

    public function testReplayMissingFileThrowsWithoutTouchingTheNetwork()
    {
        $inner = new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new \LogicException('The network must never be reached in replay mode.');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \LogicException('not implemented');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };

        $recorder = new RecorderHttpClient($inner, new FilesystemStore(null));
        $recorder->setMode(RecorderMode::REPLAY);
        $recorder->setHarFilePath($this->harFile);

        $this->expectException(TransportException::class);

        $recorder->request('GET', 'https://example.com/missing');
    }

    public function testRecordThenReplayRoundTrip()
    {
        $inner = new MockHttpClient(new MockResponse('{"ok":true}', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $recorder = new RecorderHttpClient($inner, new FilesystemStore(null));
        $recorder->setMode(RecorderMode::RECORD);
        $recorder->setHarFilePath($this->harFile);

        $response = $recorder->request('GET', 'https://example.com/ok');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"ok":true}', $response->getContent());

        $this->assertFileExists($this->harFile);

        $replay = new RecorderHttpClient(new MockHttpClient(), new FilesystemStore(null));
        $replay->setMode(RecorderMode::REPLAY);
        $replay->setHarFilePath($this->harFile);

        $replayed = $replay->request('GET', 'https://example.com/ok');
        $this->assertSame(200, $replayed->getStatusCode());
        $this->assertSame('{"ok":true}', $replayed->getContent());
    }

    public function testReplayFallsBackToRecordingWhenFileIsAbsentAndRecordIfMissingIsEnabled()
    {
        $inner = new MockHttpClient(new MockResponse('recorded', ['http_code' => 200]));

        $recorder = new RecorderHttpClient($inner, new FilesystemStore(null));
        $recorder->setMode(RecorderMode::REPLAY);
        $recorder->setHarFilePath($this->harFile);
        $recorder->setRecordIfMissing(true);

        $response = $recorder->request('GET', 'https://example.com/first-run');

        $this->assertSame('recorded', $response->getContent());
        $this->assertFileExists($this->harFile);
    }

    public function testReplayDoesNotRecordOnMissWhenRecordIfMissingIsDisabled()
    {
        $inner = new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new \LogicException('The network must never be reached when recordIfMissing is false.');
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \LogicException('not implemented');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };

        $recorder = new RecorderHttpClient($inner, new FilesystemStore(null));
        $recorder->setMode(RecorderMode::REPLAY);
        $recorder->setHarFilePath($this->harFile);

        $this->expectException(TransportException::class);

        $recorder->request('GET', 'https://example.com/first-run');
    }

    public function testRedactsSensitiveHeadersAndQueryAndBodyOnRecordButStaysReplayable()
    {
        $inner = new MockHttpClient(new MockResponse('secret-response', ['http_code' => 200]));

        $recorder = new RecorderHttpClient($inner, new FilesystemStore(null));
        $recorder->setMode(RecorderMode::RECORD);
        $recorder->setHarFilePath($this->harFile);

        $options = [
            'headers' => ['Authorization' => 'Bearer super-secret-token'],
            'body' => json_encode(['username' => 'bob', 'password' => 'hunter2']),
        ];

        $response = $recorder->request('POST', 'https://example.com/login?token=abc123&foo=bar', $options);
        $response->getContent(); // force the recording to be flushed

        $har = json_decode(file_get_contents($this->harFile), true, 512, \JSON_THROW_ON_ERROR);
        $entry = $har['log']['entries'][0];

        $this->assertStringNotContainsString('abc123', $entry['request']['url']);
        $this->assertStringContainsString('foo=bar', $entry['request']['url']);

        $authHeader = current(array_filter($entry['request']['headers'], static fn ($h) => 'authorization' === $h['name']));
        $this->assertNotFalse($authHeader);
        $this->assertStringNotContainsString('super-secret-token', $authHeader['value']);

        $storedBody = json_decode($entry['request']['postData']['text'], true);
        $this->assertSame('bob', $storedBody['username']);
        $this->assertStringNotContainsString('hunter2', $entry['request']['postData']['text']);

        // replaying the exact same live request (with its real secrets) must still match
        $replay = new RecorderHttpClient(new MockHttpClient(), new FilesystemStore(null));
        $replay->setMode(RecorderMode::REPLAY);
        $replay->setHarFilePath($this->harFile);

        $replayed = $replay->request('POST', 'https://example.com/login?token=abc123&foo=bar', $options);
        $this->assertSame('secret-response', $replayed->getContent());
    }

    public function testInstanceStateIsNotSharedAcrossRecorders()
    {
        $recorderA = new RecorderHttpClient(new MockHttpClient(), new FilesystemStore(null));
        $recorderB = new RecorderHttpClient(new MockHttpClient(), new FilesystemStore(null));

        $recorderA->setMode(RecorderMode::RECORD);

        $this->assertSame(RecorderMode::RECORD, $this->readMode($recorderA));
        $this->assertSame(RecorderMode::PASSTHROUGH, $this->readMode($recorderB));
    }

    public function testConfigureAllUpdatesEveryLiveInstance()
    {
        $recorderA = new RecorderHttpClient(new MockHttpClient(), new FilesystemStore(null));
        $recorderB = new RecorderHttpClient(new MockHttpClient(), new FilesystemStore(null));

        RecorderRegistry::configureAll(RecorderMode::REPLAY, $this->harFile);

        $this->assertSame(RecorderMode::REPLAY, $this->readMode($recorderA));
        $this->assertSame(RecorderMode::REPLAY, $this->readMode($recorderB));
    }

    public function testStreamDoesNotThrow()
    {
        $inner = new MockHttpClient(new MockResponse('streamed', ['http_code' => 200]));
        $recorder = new RecorderHttpClient($inner, new FilesystemStore(null));

        $response = $recorder->request('GET', 'https://example.com/stream');

        $chunks = '';
        foreach ($recorder->stream($response) as $chunk) {
            $chunks .= $chunk->getContent();
        }

        $this->assertSame('streamed', $chunks);
    }

    public function testStreamWorksInReplayMode()
    {
        $inner = new MockHttpClient(new MockResponse('streamed', ['http_code' => 200]));

        $recorder = new RecorderHttpClient($inner, new FilesystemStore(null));
        $recorder->setMode(RecorderMode::RECORD);
        $recorder->setHarFilePath($this->harFile);
        $recorder->request('GET', 'https://example.com/stream')->getContent();

        $replay = new RecorderHttpClient(new MockHttpClient(), new FilesystemStore(null));
        $replay->setMode(RecorderMode::REPLAY);
        $replay->setHarFilePath($this->harFile);

        $response = $replay->request('GET', 'https://example.com/stream');

        $chunks = '';
        foreach ($replay->stream($response) as $chunk) {
            $chunks .= $chunk->getContent();
        }

        $this->assertSame('streamed', $chunks);
    }

    private function readMode(RecorderHttpClient $recorder): string
    {
        $property = new \ReflectionProperty(RecorderHttpClient::class, 'mode');

        return $property->getValue($recorder);
    }
}
