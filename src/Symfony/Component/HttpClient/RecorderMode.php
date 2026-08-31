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

enum RecorderMode
{
    /**
     * Records all HTTP requests into the HAR file.
     */
    public const RECORD = 'record';

    /**
     * Replays HTTP requests from the HAR file.
     *
     * Combine with RecorderHttpClient::setRecordIfMissing(true) to fall back
     * to recording when no matching entry is found.
     */
    public const REPLAY = 'replay';

    /**
     * Completely ignores the recording system and executes requests normally.
     */
    public const PASSTHROUGH = 'passthrough';
}
