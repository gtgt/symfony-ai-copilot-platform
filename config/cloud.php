<?php

declare(strict_types=1);

namespace Symfony\Component\Config\Definition\Configurator;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

return (new ArrayNodeDefinition('cloud'))
    ->canBeDisabled()
    ->children()
        ->scalarNode('api_token')
            ->isRequired()
            ->cannotBeEmpty()
            ->info('GitHub personal access token or GitHub App user-to-server token (requires "Agent tasks" repository permissions)')
        ->end()
        ->scalarNode('owner')
            ->defaultNull()
            ->info('Default GitHub repository owner (organization or user); can be overridden per-request via "copilot_owner" option')
        ->end()
        ->scalarNode('repo')
            ->defaultNull()
            ->info('Default GitHub repository name; can be overridden per-request via "copilot_repo" option')
        ->end()
        ->scalarNode('http_client')
            ->defaultValue('http_client')
            ->info('Service ID of the HTTP client to use')
        ->end()
        ->scalarNode('base_uri')
            ->defaultValue('https://api.github.com/')
            ->info('GitHub REST API base URI')
        ->end()
        ->integerNode('poll_interval_ms')
            ->defaultValue(3000)
            ->min(500)
            ->info('Task polling interval in milliseconds (default: 3000ms)')
        ->end()
        ->integerNode('max_polls')
            ->defaultValue(200)
            ->min(1)
            ->info('Maximum number of task status polls before giving up (default: 200 ≈ 10 minutes at 3s)')
        ->end()
    ->end();
