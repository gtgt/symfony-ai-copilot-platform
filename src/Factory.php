<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot;

use Symfony\AI\Platform\Bridge\Copilot\Cli\Factory as CliFactory;
use Symfony\AI\Platform\Bridge\Copilot\CloudAgent\Factory as CloudAgentFactory;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Facade for GitHub Copilot platform bridges. Prefer adapter-specific factories for new code:
 *
 * - {@see CloudAgent\Factory} — Cloud Agent Tasks REST API ({@code api.github.com})
 * - {@see Cli\Factory}        — local GitHub Copilot CLI ({@code copilot})
 */
final class Factory
{
    public static function createCloudProvider(
        #[\SensitiveParameter] string $apiToken,
        ?string $defaultOwner = null,
        ?string $defaultRepo = null,
        ?HttpClientInterface $httpClient = null,
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = 'copilot',
        string $baseUri = 'https://api.github.com/',
        int $pollIntervalUs = 3_000_000,
        int $maxPolls = 200,
    ): ProviderInterface {
        return CloudAgentFactory::createProvider($apiToken, $defaultOwner, $defaultRepo, $httpClient, $modelCatalog, $contract, $eventDispatcher, $name, $baseUri, $pollIntervalUs, $maxPolls);
    }

    public static function createCloudPlatform(
        #[\SensitiveParameter] string $apiToken,
        ?string $defaultOwner = null,
        ?string $defaultRepo = null,
        ?HttpClientInterface $httpClient = null,
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $baseUri = 'https://api.github.com/',
        int $pollIntervalUs = 3_000_000,
        int $maxPolls = 200,
        ?ModelRouterInterface $modelRouter = null,
    ): Platform {
        return CloudAgentFactory::createPlatform($apiToken, $defaultOwner, $defaultRepo, $httpClient, $modelCatalog, $contract, $eventDispatcher, $baseUri, $pollIntervalUs, $maxPolls, $modelRouter);
    }

    /**
     * @param list<string> $availableTools
     * @param list<string> $excludedTools
     * @param list<string> $defaultArgs
     */
    public static function createCliProvider(
        #[\SensitiveParameter] ?string $token = null,
        string $binary = 'copilot',
        ?string $workspace = null,
        bool $yolo = false,
        int $timeout = 600,
        array $availableTools = [],
        array $excludedTools = [],
        ?string $configDir = null,
        array $defaultArgs = [],
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = 'copilot_cli',
    ): ProviderInterface {
        return CliFactory::createProvider($token, $binary, $workspace, $yolo, $timeout, $availableTools, $excludedTools, $configDir, $defaultArgs, $modelCatalog, $contract, $eventDispatcher, $name);
    }

    /**
     * @param list<string> $availableTools
     * @param list<string> $excludedTools
     * @param list<string> $defaultArgs
     */
    public static function createCliPlatform(
        #[\SensitiveParameter] ?string $token = null,
        string $binary = 'copilot',
        ?string $workspace = null,
        bool $yolo = false,
        int $timeout = 600,
        array $availableTools = [],
        array $excludedTools = [],
        ?string $configDir = null,
        array $defaultArgs = [],
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?ModelRouterInterface $modelRouter = null,
    ): Platform {
        return CliFactory::createPlatform($token, $binary, $workspace, $yolo, $timeout, $availableTools, $excludedTools, $configDir, $defaultArgs, $modelCatalog, $contract, $eventDispatcher, $modelRouter);
    }
}
