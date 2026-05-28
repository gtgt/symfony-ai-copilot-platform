<?php

declare(strict_types=1);

namespace Symfony\Component\Config\Definition\Configurator;

return static function (DefinitionConfigurator $configurator): void {
    $import = static fn (string $resource): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition => require __DIR__.'/'.$resource.'.php';

    $configurator->rootNode()
        ->children()
            ->append($import('cloud'))
            ->append($import('cli'))
        ->end();
};
