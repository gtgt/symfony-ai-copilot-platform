<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Extracts token usage from GitHub Copilot CLI agent results (JSONL terminal result event).
 *
 * Copilot CLI uses snake_case field names: input_tokens, output_tokens.
 */
final class TokenUsageExtractor implements TokenUsageExtractorInterface
{
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
    {
        if ($options['stream'] ?? false) {
            return null;
        }

        $data = $rawResult->getData();
        $usage = $data['usage'] ?? null;
        if (!\is_array($usage)) {
            return null;
        }

        // Copilot CLI uses snake_case; also handle camelCase for forward-compatibility.
        $input = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : (isset($usage['inputTokens']) ? (int) $usage['inputTokens'] : null);
        $output = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : (isset($usage['outputTokens']) ? (int) $usage['outputTokens'] : null);
        $cacheRead = isset($usage['cache_read_input_tokens']) ? (int) $usage['cache_read_input_tokens'] : (isset($usage['cacheReadTokens']) ? (int) $usage['cacheReadTokens'] : null);
        $cacheWrite = isset($usage['cache_creation_input_tokens']) ? (int) $usage['cache_creation_input_tokens'] : (isset($usage['cacheWriteTokens']) ? (int) $usage['cacheWriteTokens'] : null);

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
