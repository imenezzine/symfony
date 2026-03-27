<?php

namespace Symfony\Component\HttpClient\Recorder\Matcher;


use Symfony\Component\HttpClient\Har\HarFile;

/**
 * @psalm-import-type HarEntry from HarFile
 */
interface MatcherInterface
{
    /**
     * @psalm-param HarEntry $harEntry
     */
    public function matches(
        array $harEntry,
        string $method,
        string $url,
        array $options,
    ): bool;
}
