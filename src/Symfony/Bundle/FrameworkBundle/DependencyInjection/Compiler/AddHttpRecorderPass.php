<?php

namespace Symfony\Bundle\FrameworkBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\RecorderHttpClient;

class AddHttpRecorderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('http_client.recorder.store')) {
            return;
        }

        foreach ($container->findTaggedServiceIds('http_client.client') as $serviceId => $attributes) {
            $container
                ->register("{$serviceId}.recorder", RecorderHttpClient::class)
                ->setDecoratedService($serviceId)
                ->setArguments([
                    new Reference($serviceId.'.recorder.inner'),
                    new Reference('http_client.recorder.store'),
                ])
                ->addTag('http_client.client');
        }
    }
}
