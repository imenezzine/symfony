<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\PhpUnit\Attribute;

use Symfony\Component\HttpClient\RecorderMode;

/**
 * @example UseRecord('my_record.har', RecorderMode::RECORD)
 * @example UseRecord('./my_record.har', RecorderMode::RECORD)
 * @example UseRecord('../my_record.har', RecorderMode::RECORD)
 * @example UseRecord('/my_record.har', RecorderMode::RECORD)
 * @example UseRecord('@my_record.har', RecorderMode::RECORD)
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class UseRecord
{
    /**
     * @psalm-param RecorderMode::*|null $mode
     */
    public function __construct(
        public ?string $record = null,
        public ?string $mode = null,
    ) {
    }
}
