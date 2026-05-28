<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
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
        'sessionId', 'model', 'messageId', 'requestId', 'serviceRequestId',
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

        if (0 !== (int) ($data['exitCode'] ?? 0) || true === ($data['is_error'] ?? false)) {
            throw new RuntimeException((string) ($data['error'] ?? $data['content'] ?? 'GitHub Copilot CLI agent run failed.'));
        }

        if (!isset($data['content']) || '' === $data['content']) {
            throw new RuntimeException('Unexpected Copilot CLI JSON response: missing "content" field.');
        }

        $results = [];

        // Include thinking/reasoning as a ThinkingResult when present.
        if (isset($data['reasoningText']) && '' !== (string) $data['reasoningText']) {
            $results[] = new ThinkingResult(
                (string) $data['reasoningText'],
                isset($data['reasoningOpaque']) ? (string) $data['reasoningOpaque'] : null,
            );
        }

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
        $thinkingStarted = false;
        $thinkingComplete = false;
        $accumulatedThinking = '';
        $textDeltaSeen = false;

        foreach ($result->getDataStream() as $event) {
            $type = (string) ($event['type'] ?? '');
            /** @var array<string, mixed> $data */
            $data = \is_array($event['data'] ?? null) ? $event['data'] : [];

            // session.* events carry lifecycle/connection metadata — emit as ObjectResult-like metadata.
            if (str_starts_with($type, 'session.')) {
                yield new MetadataDelta($type, [] !== $data ? $data : null);
                continue;
            }

            switch ($type) {
                case 'user.message':
                    // Not assistant output; skip.
                    break;

                case 'assistant.turn_start':
                    yield new MetadataDelta('turn_start', $data);
                    break;

                case 'assistant.reasoning_delta':
                    // Incremental thinking content (streaming mode).
                    if (!$thinkingStarted) {
                        yield new ThinkingStart();
                        $thinkingStarted = true;
                    }
                    $delta = (string) ($data['deltaContent'] ?? '');
                    $accumulatedThinking .= $delta;
                    yield new ThinkingDelta($delta);
                    break;

                case 'assistant.message_start':
                    // Reasoning is finished, text message is beginning.
                    if ($thinkingStarted) {
                        yield new ThinkingComplete($accumulatedThinking);
                        $thinkingStarted = false;
                        $thinkingComplete = true;
                        $accumulatedThinking = '';
                    }
                    break;

                case 'assistant.message_delta':
                    // Incremental text content (streaming mode).
                    $textDeltaSeen = true;
                    yield new TextDelta((string) ($data['deltaContent'] ?? ''));
                    break;

                case 'assistant.message':
                    // Full message event — always present, streaming or not.

                    // Non-streaming mode: no reasoning_delta events were emitted, yield full thinking now.
                    if (!$thinkingComplete && '' !== (string) ($data['reasoningText'] ?? '')) {
                        yield new ThinkingStart();
                        yield new ThinkingDelta((string) $data['reasoningText']);
                        yield new ThinkingComplete(
                            (string) $data['reasoningText'],
                            isset($data['reasoningOpaque']) && '' !== $data['reasoningOpaque']
                                ? (string) $data['reasoningOpaque'] : null,
                        );
                        $thinkingComplete = true;
                    } elseif ($thinkingComplete && isset($data['reasoningOpaque']) && '' !== (string) $data['reasoningOpaque']) {
                        // Streaming mode: thinking was emitted incrementally; attach the opaque signature now.
                        yield new ThinkingSignature((string) $data['reasoningOpaque']);
                    }

                    // Non-streaming mode: no message_delta events; yield the full content as a single delta.
                    if (!$textDeltaSeen && '' !== (string) ($data['content'] ?? '')) {
                        yield new TextDelta((string) $data['content']);
                    }

                    // Emit tool call events if the model requested any tools.
                    $toolRequests = \is_array($data['toolRequests'] ?? null) ? $data['toolRequests'] : [];
                    if ([] !== $toolRequests) {
                        $toolCalls = [];
                        foreach ($toolRequests as $req) {
                            if (!\is_array($req)) {
                                continue;
                            }
                            $callId = (string) ($req['id'] ?? '');
                            $name = (string) ($req['name'] ?? $req['toolName'] ?? '');
                            /** @var array<string, mixed> $args */
                            $args = \is_array($req['arguments'] ?? null) ? $req['arguments'] : (\is_array($req['input'] ?? null) ? $req['input'] : []);
                            if ('' !== $callId) {
                                yield new ToolCallStart($callId, $name);
                                $toolCalls[] = new ToolCall($callId, $name, $args);
                            }
                        }
                        if ([] !== $toolCalls) {
                            yield new ToolCallComplete($toolCalls);
                        }
                    }

                    // Metadata fields from the message payload.
                    foreach (['model', 'messageId', 'requestId', 'serviceRequestId'] as $key) {
                        if (isset($data[$key])) {
                            yield new MetadataDelta($key, $data[$key]);
                        }
                    }

                    if (null !== ($tokenUsage = self::buildTokenUsage(['outputTokens' => $data['outputTokens'] ?? null]))) {
                        yield new MetadataDelta('token_usage', $tokenUsage);
                    }
                    break;

                case 'assistant.reasoning':
                    // Full reasoning event (may be ephemeral). Already covered by reasoning_delta + ThinkingComplete
                    // or by assistant.message.reasoningText above. Emit as read-only metadata for completeness.
                    if (isset($data['content']) && '' !== (string) $data['content']) {
                        yield new MetadataDelta('reasoning_full', (string) $data['content']);
                    }
                    break;

                case 'assistant.turn_end':
                    yield new MetadataDelta('turn_end', $data);
                    break;

                case 'result':
                    // Terminal event — emit session-level metadata and stop.
                    if (isset($event['sessionId'])) {
                        yield new MetadataDelta('sessionId', (string) $event['sessionId']);
                    }
                    if (isset($event['exitCode'])) {
                        yield new MetadataDelta('exitCode', (int) $event['exitCode']);
                    }
                    $cliUsage = \is_array($event['usage'] ?? null) ? $event['usage'] : [];
                    foreach (['premiumRequests', 'totalApiDurationMs', 'sessionDurationMs', 'codeChanges'] as $field) {
                        if (isset($cliUsage[$field])) {
                            yield new MetadataDelta($field, $cliUsage[$field]);
                        }
                    }
                    return;

                // Unknown event types are silently ignored for forward-compatibility.
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

        foreach (['premiumRequests', 'totalApiDurationMs', 'sessionDurationMs', 'codeChanges'] as $field) {
            if (null !== ($data[$field] ?? null)) {
                $metadata->add($field, $data[$field]);
            }
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
