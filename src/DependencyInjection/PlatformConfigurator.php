<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\DependencyInjection;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Registers GitHub Copilot platform services into the DI container by importing the
 * dedicated service config files (config/cli_services.php, config/cloud_services.php).
 */
final class PlatformConfigurator
{
    /**
     * @param array{
     *     api_token: string,
     *     owner?: string|null,
     *     repo?: string|null,
     *     http_client?: string,
     *     base_uri?: string,
     *     poll_interval_ms?: int,
     *     max_polls?: int,
     * } $config
     */
    public static function registerCloud(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container->setParameter('ai.platform.copilot.api_token', $config['api_token'] ?? '');
        $container->setParameter('ai.platform.copilot.owner', $config['owner'] ?? null);
        $container->setParameter('ai.platform.copilot.repo', $config['repo'] ?? null);
        $container->setParameter('ai.platform.copilot.base_uri', $config['base_uri'] ?? 'https://api.github.com/');
        $container->setParameter('ai.platform.copilot.poll_interval_us', ((int) ($config['poll_interval_ms'] ?? 3000)) * 1000);
        $container->setParameter('ai.platform.copilot.max_polls', (int) ($config['max_polls'] ?? 200));

        $container->setAlias('ai.platform.copilot.http_client', $config['http_client'] ?? 'http_client');

        $configurator->import('../config/cloud_services.php');

        $container->registerAliasForArgument('ai.platform.copilot', PlatformInterface::class, 'copilot');
    }

    /**
     * @param array{
     *     token?: string|null,
     *     binary?: string,
     *     workspace?: string|null,
     *     yolo?: bool,
     *     timeout?: int,
     *     available_tools?: list<string>,
     *     excluded_tools?: list<string>,
     *     config_dir?: string|null,
     *     extra_args?: list<string>,
     * } $config
     */
    public static function registerCli(array $config, ContainerConfigurator $configurator, ContainerBuilder $container, ?string $defaultWorkspace = null): void
    {
        $container->setParameter('ai.platform.copilot_cli.token', $config['token'] ?? null);
        $container->setParameter('ai.platform.copilot_cli.binary', $config['binary'] ?? 'copilot');
        $container->setParameter('ai.platform.copilot_cli.workspace', $config['workspace'] ?? $defaultWorkspace);
        $container->setParameter('ai.platform.copilot_cli.yolo', $config['yolo'] ?? false);
        $container->setParameter('ai.platform.copilot_cli.timeout', (int) ($config['timeout'] ?? 600));
        $container->setParameter('ai.platform.copilot_cli.available_tools', $config['available_tools'] ?? []);
        $container->setParameter('ai.platform.copilot_cli.excluded_tools', $config['excluded_tools'] ?? []);
        $container->setParameter('ai.platform.copilot_cli.config_dir', $config['config_dir'] ?? null);
        $container->setParameter('ai.platform.copilot_cli.extra_args', $config['extra_args'] ?? []);

        $configurator->import('../config/cli_services.php');

        $container->registerAliasForArgument('ai.platform.copilot_cli', PlatformInterface::class, 'copilot_cli');
    }
}
