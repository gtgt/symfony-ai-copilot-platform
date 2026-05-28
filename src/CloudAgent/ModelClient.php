<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\CloudAgent;

use Symfony\AI\Platform\Bridge\Copilot\CloudAgent\Api\RestClient;
use Symfony\AI\Platform\Bridge\Copilot\MessagePayload;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Maps each Platform invocation to a GitHub Copilot Cloud Agent task:
 *   POST /agents/repos/{owner}/{repo}/tasks, then polls for completion.
 *
 * The `owner` and `repo` must be provided either via constructor defaults or
 * per-request options (`copilot_owner`, `copilot_repo`).
 */
final class ModelClient implements ModelClientInterface
{
    public function __construct(
        private readonly RestClient $api,
        private readonly ?string $defaultOwner = null,
        private readonly ?string $defaultRepo = null,
        private readonly int $pollIntervalUs = 3_000_000,
        private readonly int $maxPolls = 200,
    ) {
    }

    public static function fromHttpClient(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] string $apiToken,
        string $baseUri = 'https://api.github.com/',
        ?string $defaultOwner = null,
        ?string $defaultRepo = null,
        int $pollIntervalUs = 3_000_000,
        int $maxPolls = 200,
    ): self {
        return new self(
            new RestClient($httpClient, $apiToken, $baseUri),
            $defaultOwner,
            $defaultRepo,
            $pollIntervalUs,
            $maxPolls,
        );
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Agent;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!\is_array($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, string given to "%s".', self::class));
        }

        $owner = (string) ($options['copilot_owner'] ?? $this->defaultOwner ?? '');
        $repo = (string) ($options['copilot_repo'] ?? $this->defaultRepo ?? '');

        if ('' === $owner) {
            throw new InvalidArgumentException('GitHub repository owner must be configured via "cloud.owner" or option "copilot_owner".');
        }
        if ('' === $repo) {
            throw new InvalidArgumentException('GitHub repository name must be configured via "cloud.repo" or option "copilot_repo".');
        }

        $promptText = MessagePayload::flattenMessages(MessagePayload::requireMessages($payload));
        $body = $this->buildRequestBody($model, $promptText, $options);

        $task = $this->api->createTask($owner, $repo, $body);
        $taskId = (string) $task['id'];

        $pollIntervalUs = isset($options['copilot_poll_interval_ms'])
            ? (int) $options['copilot_poll_interval_ms'] * 1000
            : $this->pollIntervalUs;

        $maxPolls = isset($options['copilot_max_polls'])
            ? (int) $options['copilot_max_polls']
            : $this->maxPolls;

        return new RawCloudResult($this->api, $owner, $repo, $taskId, $pollIntervalUs, $maxPolls);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildRequestBody(Model $model, string $promptText, array $options): array
    {
        $body = ['prompt' => $promptText];

        $modelName = $model->getName();
        if ('default' !== $modelName) {
            $body['model'] = $modelName;
        }

        if (isset($options['copilot_base_ref']) && '' !== (string) $options['copilot_base_ref']) {
            $body['base_ref'] = (string) $options['copilot_base_ref'];
        }

        if (isset($options['copilot_head_ref']) && '' !== (string) $options['copilot_head_ref']) {
            $body['head_ref'] = (string) $options['copilot_head_ref'];
        }

        if (isset($options['copilot_create_pull_request'])) {
            $body['create_pull_request'] = (bool) $options['copilot_create_pull_request'];
        }

        return $body;
    }
}
