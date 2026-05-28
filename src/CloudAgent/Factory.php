<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\CloudAgent;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Factory
{
    public static function createProvider(
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
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): ProviderInterface {
        $httpClient ??= HttpClient::create();
        $modelCatalog ??= new ModelCatalog();

        return new Provider(
            $name,
            [
                ModelClient::fromHttpClient($httpClient, $apiToken, $baseUri, $defaultOwner, $defaultRepo, $pollIntervalUs, $maxPolls),
            ],
            [
                new ResultConverter($tokenUsageExtractor),
            ],
            $modelCatalog,
            $contract ?? Contract::create(),
            $eventDispatcher,
        );
    }

    public static function createPlatform(
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
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): Platform {
        return new Platform(
            [self::createProvider($apiToken, $defaultOwner, $defaultRepo, $httpClient, $modelCatalog, $contract, $eventDispatcher, 'copilot', $baseUri, $pollIntervalUs, $maxPolls, $tokenUsageExtractor)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}
