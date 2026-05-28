<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Converts GitHub Copilot CLI JSONL output into platform result objects.
 *
 * Non-streaming: blocks until the process exits and parses the terminal result event.
 * Streaming:     yields deltas as JSONL events are emitted by the copilot process.
 */
final class ResultConverter implements ResultConverterInterface
{
    private const METADATA_FIELDS = [
        'session_id', 'request_id', 'duration_ms', 'model',
    ];

    private readonly TokenUsageExtractorInterface $tokenUsageExtractor;

    public function __construct(?TokenUsageExtractorInterface $tokenUsageExtractor = null)
    {
        $this->tokenUsageExtractor = $tokenUsageExtractor ?? new TokenUsageExtractor();
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

        if ([] === $data) {
            throw new RuntimeException('GitHub Copilot CLI did not return any result.');
        }

        $subtype = (string) ($data['subtype'] ?? '');
        if ('error_during_generation' === $subtype || true === ($data['is_error'] ?? false)) {
            throw new RuntimeException((string) ($data['error'] ?? $data['content'] ?? 'GitHub Copilot CLI agent run failed.'));
        }

        if (!isset($data['content'])) {
            throw new RuntimeException('Unexpected Copilot CLI JSON response: missing "content" field.');
        }

        $results = [];
        foreach ($data['tool_calls'] ?? [] as $toolCall) {
            if (!\is_array($toolCall) || !isset($toolCall['id'], $toolCall['name'])) {
                continue;
            }
            /** @var array<string, mixed> $args */
            $args = \is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];
            $results[] = new ToolCallResult([new ToolCall(
                (string) $toolCall['id'],
                (string) $toolCall['name'],
                $args,
            )]);
        }

        $results[] = new TextResult((string) $data['content']);

        $finalResult = 1 === \count($results) ? $results[0] : new MultiPartResult($results);
        $this->attachMetadata($finalResult, $data);

        return $finalResult;
    }

    public function getTokenUsageExtractor(): TokenUsageExtractorInterface
    {
        return $this->tokenUsageExtractor;
    }

    /**
     * @return \Generator<int, \Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface>
     */
    private function convertStream(RawResultInterface $result): \Generator
    {
        /** @var array<string, array{id: string, name: string, arguments: array<string, mixed>}> $pendingToolCalls */
        $pendingToolCalls = [];

        foreach ($result->getDataStream() as $event) {
            $type = $event['type'] ?? null;
            $subtype = $event['subtype'] ?? null;

            switch ($type) {
                case 'system':
                    if ('init' === $subtype) {
                        yield new MetadataDelta('session', [
                            'session_id' => $event['session_id'] ?? null,
                            'model' => $event['model'] ?? null,
                            'cwd' => $event['cwd'] ?? null,
                        ]);
                    }
                    break;

                case 'thinking':
                    yield new ThinkingDelta((string) ($event['thinking'] ?? $event['text'] ?? ''));
                    break;

                case 'assistant':
                    $content = $event['message']['content'] ?? $event['content'] ?? [];
                    if (\is_array($content)) {
                        foreach ($content as $block) {
                            if (\is_array($block)) {
                                if ('text' === ($block['type'] ?? null) && isset($block['text'])) {
                                    yield new TextDelta((string) $block['text']);
                                } elseif ('thinking' === ($block['type'] ?? null) && isset($block['thinking'])) {
                                    yield new ThinkingDelta((string) $block['thinking']);
                                }
                            } elseif (\is_string($block)) {
                                yield new TextDelta($block);
                            }
                        }
                    } elseif (\is_string($content)) {
                        yield new TextDelta($content);
                    }
                    break;

                case 'tool_use':
                    $callId = (string) ($event['id'] ?? '');
                    $name = (string) ($event['name'] ?? '');
                    /** @var array<string, mixed> $args */
                    $args = \is_array($event['input'] ?? null) ? $event['input'] : [];

                    if ('' !== $callId) {
                        $pendingToolCalls[$callId] = ['id' => $callId, 'name' => $name, 'arguments' => $args];
                        yield new ToolCallStart($callId, $name);
                        if ([] !== $args) {
                            try {
                                yield new ToolInputDelta($callId, $name, json_encode($args, \JSON_THROW_ON_ERROR));
                            } catch (\JsonException) {
                                // ignore; tool args could not be re-encoded
                            }
                        }
                    }
                    break;

                case 'tool_result':
                    $callId = (string) ($event['tool_use_id'] ?? '');
                    if ('' !== $callId && isset($pendingToolCalls[$callId])) {
                        yield new MetadataDelta('tool_result.'.$callId, $event['content'] ?? null);
                    }
                    break;

                case 'result':
                    if ([] !== $pendingToolCalls) {
                        $toolCalls = array_map(
                            static fn (array $tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments']),
                            array_values($pendingToolCalls),
                        );
                        $pendingToolCalls = [];
                        yield new ToolCallComplete($toolCalls);
                    }

                    foreach (['session_id', 'request_id', 'duration_ms'] as $key) {
                        if (isset($event[$key])) {
                            yield new MetadataDelta($key, $event[$key]);
                        }
                    }

                    if (null !== ($tokenUsage = self::buildTokenUsage($event['usage'] ?? null))) {
                        yield new MetadataDelta('token_usage', $tokenUsage);
                    }

                    if (isset($event['content']) && \is_string($event['content']) && '' !== $event['content']) {
                        yield new TextDelta($event['content']);
                    }
                    return;

                // Unknown events are ignored for forward-compatibility.
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function attachMetadata(ResultInterface $result, array $data): void
    {
        $metadata = $result->getMetadata();
        foreach (self::METADATA_FIELDS as $field) {
            if (isset($data[$field])) {
                $metadata->add($field, $data[$field]);
            }
        }

        if (null !== ($tokenUsage = self::buildTokenUsage($data['usage'] ?? null))) {
            $metadata->add('token_usage', $tokenUsage);
        }
    }

    private static function buildTokenUsage(mixed $usage): ?TokenUsage
    {
        if (!\is_array($usage)) {
            return null;
        }

        $input = isset($usage['inputTokens']) ? (int) $usage['inputTokens'] : null;
        $output = isset($usage['outputTokens']) ? (int) $usage['outputTokens'] : null;
        $cacheRead = isset($usage['cacheReadTokens']) ? (int) $usage['cacheReadTokens'] : null;
        $cacheWrite = isset($usage['cacheWriteTokens']) ? (int) $usage['cacheWriteTokens'] : null;

        if (null === $input && null === $output && null === $cacheRead && null === $cacheWrite) {
            return null;
        }

        $total = null;
        if (null !== $input || null !== $output) {
            $total = ($input ?? 0) + ($output ?? 0);
        }

        return new TokenUsage(
            promptTokens: $input,
            completionTokens: $output,
            cacheCreationTokens: $cacheWrite,
            cacheReadTokens: $cacheRead,
            totalTokens: $total,
        );
    }
}
