<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Test;

use Symfony\Component\HttpClient\Har\HarFile;
use Symfony\Component\HttpClient\Recorder\Matcher\MatcherInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * See: https://w3c.github.io/web-performance/specs/HAR/Overview.html.
 *
 * @author Gary PEGEOT <garypegeot@gmail.com>
 */
class HarFileResponseFactory
{
    public function __construct(
        private string $archiveFile,
        private ?MatcherInterface $matcher = null,
    ) {
    }

    public function setArchiveFile(string $archiveFile): void
    {
        $this->archiveFile = $archiveFile;
    }

    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        return HarFile::fromFile($this->archiveFile)->findResponse($method, $url, $options, $this->matcher);
    }
}
