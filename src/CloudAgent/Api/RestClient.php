<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\CloudAgent\Api;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Low-level HTTP client for GitHub Copilot Cloud Agent Tasks REST API.
 *
 * API reference: https://docs.github.com/en/rest/agent-tasks/agent-tasks
 */
final class RestClient
{
    private const DEFAULT_BASE_URI = 'https://api.github.com/';
    private const API_VERSION = '2026-03-10';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiToken,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        if ('' === $this->apiToken) {
            throw new InvalidArgumentException('GitHub Copilot API token cannot be empty.');
        }
    }

    /**
     * Creates a new Copilot cloud agent task for the given repository.
     *
     * @param array{
     *     prompt: string,
     *     base_ref?: string|null,
     *     head_ref?: string|null,
     *     model?: string|null,
     *     create_pull_request?: bool,
     * } $body
     *
     * @return array{id: string, state: string, html_url?: string}
     */
    public function createTask(string $owner, string $repo, array $body): array
    {
        $url = $this->endpoint(\sprintf('/agents/repos/%s/%s/tasks', rawurlencode($owner), rawurlencode($repo)));

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(),
            'json' => $body,
        ]);

        $this->assertSuccess($response, [401 => AuthenticationException::class]);

        /** @var array{id?: string, state?: string} $data */
        $data = $response->toArray(false);

        if (!\is_string($data['id'] ?? null)) {
            throw new RuntimeException('Unexpected GitHub Copilot API response: missing task id.');
        }

        return $data;
    }

    /**
     * Retrieves the current state of a task.
     *
     * @return array{id: string, state: string, artifacts?: array<mixed>, sessions?: array<mixed>}
     */
    public function getTask(string $owner, string $repo, string $taskId): array
    {
        $url = $this->endpoint(\sprintf(
            '/agents/repos/%s/%s/tasks/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($taskId),
        ));

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->defaultHeaders(),
        ]);

        $this->assertSuccess($response, [401 => AuthenticationException::class]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return [
            'Authorization' => \sprintf('Bearer %s', $this->apiToken),
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUri, '/').$path;
    }

    /**
     * @param array<int, class-string<\Throwable>> $exceptionMap
     */
    private function assertSuccess(ResponseInterface $response, array $exceptionMap = []): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        foreach ($exceptionMap as $code => $exceptionClass) {
            if ($status === $code) {
                throw new $exceptionClass($response->getContent(false));
            }
        }

        throw new BadRequestException($response->getContent(false));
    }
}
