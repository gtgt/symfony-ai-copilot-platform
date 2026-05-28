<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\AI\Platform\Bridge\Copilot\Cli\ModelClient;
use Symfony\AI\Platform\Bridge\Copilot\Cli\ResultConverter;
use Symfony\AI\Platform\Bridge\Copilot\Cli\TokenUsageExtractor;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('ai.platform.copilot_cli.token_usage_extractor', TokenUsageExtractor::class)
        ->tag('ai.platform.token_usage_extractor', ['provider' => 'copilot_cli']);

    $services->alias(TokenUsageExtractorInterface::class.' $copilotCliTokenUsageExtractor', 'ai.platform.copilot_cli.token_usage_extractor');

    $services->set('ai.platform.copilot_cli.result_converter', ResultConverter::class)
        ->args([service('ai.platform.copilot_cli.token_usage_extractor')])
        ->tag('ai.platform.result_converter', ['provider' => 'copilot_cli']);

    $services->alias(ResultConverterInterface::class.' $copilotCliResultConverter', 'ai.platform.copilot_cli.result_converter');

    $services->set('ai.platform.copilot_cli.model_client', ModelClient::class)
        ->args([
            param('ai.platform.copilot_cli.binary'),
            param('ai.platform.copilot_cli.token'),
            param('ai.platform.copilot_cli.workspace'),
            param('ai.platform.copilot_cli.yolo'),
            param('ai.platform.copilot_cli.timeout'),
            param('ai.platform.copilot_cli.available_tools'),
            param('ai.platform.copilot_cli.excluded_tools'),
            param('ai.platform.copilot_cli.config_dir'),
            param('ai.platform.copilot_cli.extra_args'),
        ])
        ->tag('ai.platform.model_client', ['provider' => 'copilot_cli']);

    $services->set('ai.platform.copilot_cli.contract', Contract::class)
        ->factory([Contract::class, 'create']);

    $services->set('ai.platform.copilot_cli.provider', Provider::class)
        ->args([
            'copilot_cli',
            [service('ai.platform.copilot_cli.model_client')],
            [service('ai.platform.copilot_cli.result_converter')],
            service('ai.platform.model_catalog.copilot_cli'),
            service('ai.platform.copilot_cli.contract'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ]);

    $services->alias(ProviderInterface::class.' $copilotCliProvider', 'ai.platform.copilot_cli.provider');

    $services->set('ai.platform.copilot_cli.model_router', CatalogBasedModelRouter::class);

    $services->set('ai.platform.copilot_cli', Platform::class)
        ->args([
            [service('ai.platform.copilot_cli.provider')],
            service('ai.platform.copilot_cli.model_router'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ])
        ->tag('ai.platform', ['name' => 'copilot_cli'])
        ->tag('proxy', ['interface' => PlatformInterface::class]);

    $services->alias(PlatformInterface::class.' $copilotCli', 'ai.platform.copilot_cli');
};
