<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\AI\Platform\Bridge\Copilot\CloudAgent\Api\RestClient;
use Symfony\AI\Platform\Bridge\Copilot\CloudAgent\ModelClient;
use Symfony\AI\Platform\Bridge\Copilot\CloudAgent\ResultConverter;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\ResultConverterInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('ai.platform.copilot.rest_client', RestClient::class)
        ->args([
            service('ai.platform.copilot.http_client'),
            param('ai.platform.copilot.api_token'),
            param('ai.platform.copilot.base_uri'),
        ]);

    $services->set('ai.platform.copilot.result_converter', ResultConverter::class)
        ->args([null])
        ->tag('ai.platform.result_converter', ['provider' => 'copilot']);

    $services->alias(ResultConverterInterface::class.' $copilotResultConverter', 'ai.platform.copilot.result_converter');

    $services->set('ai.platform.copilot.model_client', ModelClient::class)
        ->args([
            service('ai.platform.copilot.rest_client'),
            param('ai.platform.copilot.owner'),
            param('ai.platform.copilot.repo'),
            param('ai.platform.copilot.poll_interval_us'),
            param('ai.platform.copilot.max_polls'),
        ])
        ->tag('ai.platform.model_client', ['provider' => 'copilot']);

    $services->set('ai.platform.copilot.contract', Contract::class)
        ->factory([Contract::class, 'create']);

    $services->set('ai.platform.copilot.provider', Provider::class)
        ->args([
            'copilot',
            [service('ai.platform.copilot.model_client')],
            [service('ai.platform.copilot.result_converter')],
            service('ai.platform.model_catalog.copilot'),
            service('ai.platform.copilot.contract'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ]);

    $services->alias(ProviderInterface::class.' $copilotProvider', 'ai.platform.copilot.provider');

    $services->set('ai.platform.copilot.model_router', CatalogBasedModelRouter::class);

    $services->set('ai.platform.copilot', Platform::class)
        ->args([
            [service('ai.platform.copilot.provider')],
            service('ai.platform.copilot.model_router'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ])
        ->tag('ai.platform', ['name' => 'copilot'])
        ->tag('proxy', ['interface' => PlatformInterface::class]);

    $services->alias(PlatformInterface::class.' $copilot', 'ai.platform.copilot');
};
