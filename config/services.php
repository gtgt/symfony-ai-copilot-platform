<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Copilot\Cli\ModelCatalog as CliModelCatalog;
use Symfony\AI\Platform\Bridge\Copilot\CloudAgent\ModelCatalog as CloudModelCatalog;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('ai.platform.model_catalog.copilot', CloudModelCatalog::class)
        ->set('ai.platform.model_catalog.copilot_cli', CliModelCatalog::class)
    ;
};
