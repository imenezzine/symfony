<?php

namespace Symfony\Component\HttpClient;

enum RecorderMode: string
{
    /**
     * Records all HTTP requests into the HAR file.
     */
    public const RECORD = 'record';

    /**
     * Replays HTTP requests from the HAR file.
     */
    public const REPLAY = 'replay';

    /**
     * Tries to find an existing record to replay, if missing executes the request normally then records it.
     */
    public const REPLAY_AND_RECORD_IF_MISSING = 'replay_and_record_if_missing';

    /**
     * Completely ignores the recording system and executes requests normally.
     */
    public const PASSTHROUGH = 'passthrough';
}
