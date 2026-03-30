<?php

namespace Symfony\Bridge\PhpUnit\Extension;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use Symfony\Bridge\PhpUnit\Attribute\UseRecord;
use Symfony\Component\HttpClient\RecorderHttpClient;
use Symfony\Component\HttpClient\RecorderMode;
use Symfony\Component\HttpFoundation\Exception\LogicException;
use PHPUnit\Event\Code\TestMethod;

final class RecorderSubscriber implements PreparationStartedSubscriber
{
    public function __construct(
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

        $attributeData = $this->loadUseRecordAttribute($test);

        if (false === $attributeData) {
            return;
        }

        $currentTestDir = \dirname($test->file());

        /**
         * @see https://regex101.com/r/m2kwWt/1
         */
        $success = preg_match('/(?<className>[^\\\\]*)$/', $test->className(), $matches);
        if ($success !== 1) {
            throw new LogicException("Failed to extract class name from test class: {$test->className()}");
        }

        $record = $attributeData[0] ?: $currentTestDir.'/'.$matches['className'].'/'.$test->methodName().'.har';

        $mode = $attributeData[1] ?: RecorderMode::REPLAY_AND_RECORD_IF_MISSING;

        if (\str_starts_with($record, '@')) {
            $record = \substr($record, 1);
            $record = "{$this->defaultDirectory}{$record}";
        } elseif (false === \str_starts_with($record, '/')) {
            $record = "{$currentTestDir}/{$record}";
        }

        RecorderHttpClient::setRecord($record); // TODO: When creating test for this method: make sure it is always absolute
        RecorderHttpClient::setMode($mode);
    }

    /**
     * @psalm-return false|array{0: string, 1: RecorderMode::*|string}
     */
    private function loadUseRecordAttribute(TestMethod $test): false|array
    {
        $className = $test->className();
        $methodName = $test->methodName();

        $attributeFound = false;
        $mode = null;
        $record = null;


        if ($attributes = (new \ReflectionMethod($className, $methodName))->getAttributes(UseRecord::class)) {
            /** @var UseRecord $inst */
            $inst = $attributes[0]->newInstance();
            $record = $inst->record;
            $mode = $inst->mode;
            $attributeFound = true;
        }

        if (false === $attributeFound) {
            return false;
        }

        return [$record, $mode];
    }
}
