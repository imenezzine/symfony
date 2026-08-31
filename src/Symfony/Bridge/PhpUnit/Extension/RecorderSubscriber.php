<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\PhpUnit\Extension;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use Symfony\Bridge\PhpUnit\Attribute\UseRecord;
use Symfony\Bridge\PhpUnit\Metadata\AttributeReader;
use Symfony\Component\HttpClient\Recorder\RecorderRegistry;
use Symfony\Component\HttpClient\RecorderMode;

final class RecorderSubscriber implements PreparationStartedSubscriber
{
    public function __construct(
        private AttributeReader $reader,
        private string $defaultDirectory,
    ) {
    }

    public function notify(PreparationStarted $event): void
    {
        RecorderRegistry::configureAll(RecorderMode::PASSTHROUGH, 'default.har');

        $test = $event->test();

        if (!$test instanceof TestMethod) {
            return;
        }

        $attributes = $this->reader->forClassAndMethod($test->className(), $test->methodName(), UseRecord::class);
        if ([] === $attributes) {
            return;
        }

        // the method-level attribute, when present, takes precedence over the class-level one
        $attribute = $attributes[array_key_last($attributes)];

        $currentTestDir = \dirname($test->file());

        /**
         * @see https://regex101.com/r/m2kwWt/1
         */
        $success = preg_match('/(?<className>[^\\\\]*)$/', $test->className(), $matches);
        if (1 !== $success) {
            throw new \LogicException("Failed to extract class name from test class: {$test->className()}");
        }

        $record = $attribute->record ?: $currentTestDir.'/'.$matches['className'].'/'.$test->methodName().'.har';

        // fail closed by default: a miss on replay must never reach the network,
        // recording requires an explicit opt-in via UseRecord's $recordIfMissing argument
        $mode = $attribute->mode ?: RecorderMode::REPLAY;

        if (\str_starts_with($record, '@')) {
            $record = \substr($record, 1);
            $record = "{$this->defaultDirectory}{$record}";
        } elseif (!\str_starts_with($record, '/')) {
            $record = "{$currentTestDir}/{$record}";
        }

        RecorderRegistry::configureAll($mode, $record, $attribute->recordIfMissing);
    }
}
