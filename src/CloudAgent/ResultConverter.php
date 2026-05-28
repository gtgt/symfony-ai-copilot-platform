<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\CloudAgent;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Converts a GitHub Copilot Cloud Agent task result into platform result objects.
 *
 * The Cloud Agent produces code changes and (optionally) pull requests rather than
 * plain-text responses. The TextResult content is a human-readable summary of the
 * task outcome; structured data (task ID, state, branch, PR) is stored in metadata.
 *
 * Streaming mode yields {@see MetadataDelta} frames on each poll cycle.
 */
final class ResultConverter implements ResultConverterInterface
{
    public function __construct(
        private readonly ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ) {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Agent;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();
        $state = (string) ($data['state'] ?? 'unknown');

        if ('failed' === $state) {
            throw new RuntimeException(\sprintf(
                'GitHub Copilot Cloud Agent task "%s" failed.',
                (string) ($data['id'] ?? 'unknown'),
            ));
        }
        if ('timed_out' === $state) {
            throw new RuntimeException(\sprintf(
                'GitHub Copilot Cloud Agent task "%s" timed out.',
                (string) ($data['id'] ?? 'unknown'),
            ));
        }
        if ('cancelled' === $state) {
            throw new RuntimeException(\sprintf(
                'GitHub Copilot Cloud Agent task "%s" was cancelled.',
                (string) ($data['id'] ?? 'unknown'),
            ));
        }

        $summary = $this->buildSummary($data);
        $textResult = new TextResult($summary);
        $metadata = $textResult->getMetadata();

        $metadata->add('copilot_task_id', $data['id'] ?? null);
        $metadata->add('copilot_task_state', $state);

        if (isset($data['html_url'])) {
            $metadata->add('copilot_task_url', $data['html_url']);
        }

        $session = ($data['sessions'] ?? [])[0] ?? null;
        if (\is_array($session)) {
            if (isset($session['head_ref'])) {
                $metadata->add('copilot_head_ref', $session['head_ref']);
            }
            if (isset($session['base_ref'])) {
                $metadata->add('copilot_base_ref', $session['base_ref']);
            }
            if (isset($session['model'])) {
                $metadata->add('copilot_model', $session['model']);
            }
        }

        foreach ($data['artifacts'] ?? [] as $artifact) {
            if (!\is_array($artifact)) {
                continue;
            }
            if ('pull' === ($artifact['type'] ?? null) && isset($artifact['data']['id'])) {
                $metadata->add('copilot_pull_request_id', $artifact['data']['id']);
            }
        }

        return $textResult;
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return $this->tokenUsageExtractor;
    }

    /**
     * @return \Generator<int, \Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface>
     */
    private function convertStream(RawResultInterface $result): \Generator
    {
        foreach ($result->getDataStream() as $task) {
            $state = (string) ($task['state'] ?? 'unknown');
            yield new MetadataDelta('copilot_task_state', $state);

            if (isset($task['id'])) {
                yield new MetadataDelta('copilot_task_id', $task['id']);
            }

            $session = ($task['sessions'] ?? [])[0] ?? null;
            if (\is_array($session) && isset($session['head_ref'])) {
                yield new MetadataDelta('copilot_head_ref', $session['head_ref']);
            }

            foreach ($task['artifacts'] ?? [] as $artifact) {
                if (\is_array($artifact) && 'pull' === ($artifact['type'] ?? null) && isset($artifact['data']['id'])) {
                    yield new MetadataDelta('copilot_pull_request_id', $artifact['data']['id']);
                }
            }

            if (\in_array($state, ['completed', 'failed', 'timed_out', 'cancelled'], true)) {
                return;
            }
        }
    }

    /**
     * Builds a human-readable summary from the terminal task payload.
     *
     * @param array<string, mixed> $data
     */
    private function buildSummary(array $data): string
    {
        $state = (string) ($data['state'] ?? 'unknown');
        $taskId = (string) ($data['id'] ?? 'unknown');
        $parts = [\sprintf('Task %s completed with state: %s', $taskId, $state)];

        $session = ($data['sessions'] ?? [])[0] ?? null;
        if (\is_array($session)) {
            if (isset($session['head_ref'])) {
                $parts[] = \sprintf('Branch: %s', $session['head_ref']);
            }
            if (isset($session['base_ref'])) {
                $parts[] = \sprintf('Base: %s', $session['base_ref']);
            }
        }

        foreach ($data['artifacts'] ?? [] as $artifact) {
            if (!\is_array($artifact)) {
                continue;
            }
            if ('pull' === ($artifact['type'] ?? null) && isset($artifact['data']['id'])) {
                $parts[] = \sprintf('Pull Request: #%s', $artifact['data']['id']);
            }
        }

        if (isset($data['html_url'])) {
            $parts[] = \sprintf('URL: %s', $data['html_url']);
        }

        return implode("\n", $parts);
    }
}
