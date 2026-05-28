<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Extracts token usage from GitHub Copilot CLI agent results.
 *
 * The Copilot CLI reports token counts on the {@code assistant.message} event
 * ({@code data.outputTokens}), which {@see RawProcessResult::getData()} normalises
 * into {@code usage.outputTokens}. Input/cache tokens are not exposed by the CLI.
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
