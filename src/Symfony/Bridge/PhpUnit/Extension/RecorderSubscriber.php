<?php

namespace Symfony\Bridge\PhpUnit\Extension;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use Symfony\Bridge\PhpUnit\Attribute\UseRecord;
use Symfony\Bridge\PhpUnit\Metadata\AttributeReader;
use Symfony\Component\HttpClient\RecorderHttpClient;
use Symfony\Component\HttpClient\RecorderMode;
use Symfony\Component\HttpFoundation\Exception\LogicException;
use PHPUnit\Event\Code\TestMethod;

final class RecorderSubscriber implements PreparationStartedSubscriber
{
    public function __construct(
        private AttributeReader $reader,
        private string $defaultDirectory,
    ) {
    }

    public function notify(PreparationStarted $event): void
    {
        RecorderHttpClient::setRecord('default.har');
        RecorderHttpClient::setMode(RecorderMode::PASSTHROUGH);

        $test = $event->test();

        if (!$test instanceof TestMethod) {
            return;
        }

        $attributes = $this->reader->forMethod($test->className(), $test->methodName(), UseRecord::class);
        if ([] === $attributes) {
            return;
        }

        $attribute = $attributes[0];

        $currentTestDir = \dirname($test->file());

        /**
         * @see https://regex101.com/r/m2kwWt/1
         */
        $success = preg_match('/(?<className>[^\\\\]*)$/', $test->className(), $matches);
        if ($success !== 1) {
            throw new LogicException("Failed to extract class name from test class: {$test->className()}");
        }

        $record = $attribute->record ?: $currentTestDir.'/'.$matches['className'].'/'.$test->methodName().'.har';

        $mode = $attribute->mode ?: RecorderMode::REPLAY_AND_RECORD_IF_MISSING;

        if (\str_starts_with($record, '@')) {
            $record = \substr($record, 1);
            $record = "{$this->defaultDirectory}{$record}";
        } elseif (false === \str_starts_with($record, '/')) {
            $record = "{$currentTestDir}/{$record}";
        }

        RecorderHttpClient::setRecord($record); // TODO: When creating test for this method: make sure it is always absolute
        RecorderHttpClient::setMode($mode);
    }
}
