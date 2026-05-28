<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class Factory
{
    /**
     * @param list<string> $availableTools
     * @param list<string> $excludedTools
     * @param list<string> $defaultArgs
     */
    public static function createProvider(
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
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): ProviderInterface {
        $modelCatalog ??= new ModelCatalog();

        return new Provider(
            $name,
            [
                new ModelClient($binary, $token, $workspace, $yolo, $timeout, $availableTools, $excludedTools, $configDir, $defaultArgs),
            ],
            [
                new ResultConverter($tokenUsageExtractor),
            ],
            $modelCatalog,
            $contract ?? Contract::create(),
            $eventDispatcher,
        );
    }

    /**
     * @param list<string> $availableTools
     * @param list<string> $excludedTools
     * @param list<string> $defaultArgs
     */
    public static function createPlatform(
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
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): Platform {
        return new Platform(
            [self::createProvider($token, $binary, $workspace, $yolo, $timeout, $availableTools, $excludedTools, $configDir, $defaultArgs, $modelCatalog, $contract, $eventDispatcher, 'copilot_cli', $tokenUsageExtractor)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}
