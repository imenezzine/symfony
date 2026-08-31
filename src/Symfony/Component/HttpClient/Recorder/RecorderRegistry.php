<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recorder;

use Symfony\Component\HttpClient\RecorderHttpClient;

/**
 * Broadcasts mode/HAR-file changes to every live RecorderHttpClient instance.
 *
 * This global registry exists because PHPUnit's PreparationStarted event
 * fires before any service container is available, so per-test configuration
 * cannot be routed through dependency injection.
 */
final class RecorderRegistry
{
    private static \WeakMap $instances;

    public static function register(RecorderHttpClient $recorder): void
    {
        self::$instances ??= new \WeakMap();
        self::$instances[$recorder] = true;
    }

    public static function configureAll(string $mode, string $harFilePath, bool $recordIfMissing = false): void
    {
        foreach (self::$instances ?? new \WeakMap() as $recorder => $_) {
            $recorder->setMode($mode);
            $recorder->setHarFilePath($harFilePath);
            $recorder->setRecordIfMissing($recordIfMissing);
        }
    }
}
