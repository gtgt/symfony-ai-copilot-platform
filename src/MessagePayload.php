<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot;

use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * Normalizes Symfony AI message payloads into a single prompt string for GitHub Copilot backends.
 */
final class MessagePayload
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<mixed>
     */
    public static function requireMessages(array $payload): array
    {
        $messages = $payload['messages'] ?? null;
        if (!\is_array($messages)) {
            throw new InvalidArgumentException('Copilot bridge expects a normalized payload with a "messages" key (use default Contract with MessageBag input).');
        }

        return $messages;
    }

    /**
     * @param list<mixed> $messages
     */
    public static function flattenMessages(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $role = $message['role'] ?? 'unknown';
            $content = $message['content'] ?? '';
            if (\is_array($content)) {
                $parts = [];
                foreach ($content as $block) {
                    if (\is_array($block) && isset($block['text'])) {
                        $parts[] = (string) $block['text'];
                    } elseif (\is_string($block)) {
                        $parts[] = $block;
                    }
                }
                $content = implode("\n", $parts);
            }
            $lines[] = \sprintf("[%s]\n%s", $role, trim((string) $content));
        }

        return implode("\n\n", $lines);
    }
}
