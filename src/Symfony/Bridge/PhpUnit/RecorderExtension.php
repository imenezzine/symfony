<?php

namespace Symfony\Bridge\PhpUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Symfony\Bridge\PhpUnit\Extension\RecorderSubscriber;
use Symfony\Bridge\PhpUnit\Metadata\AttributeReader;

final class RecorderExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $defaultDirectory = null;

        if ($parameters->has('defaultDirectory')) {
            $defaultDirectory = \realpath($parameters->get('defaultDirectory'));
        }

        $defaultDirectory ??= \dirname($configuration->configurationFile()).'/tests/fixtures/records/';

        $reader = new AttributeReader();

        $facade->registerSubscriber(new RecorderSubscriber($reader, $defaultDirectory));
    }
}
