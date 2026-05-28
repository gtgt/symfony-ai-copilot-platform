<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

/**
 * Wraps a Symfony Process running the GitHub Copilot CLI as a RawResultInterface.
 *
 * The CLI emits JSONL (newline-delimited JSON) with the following event types:
 *
 *  Session/lifecycle events (ephemeral metadata):
 *  - {"type":"session.mcp_server_status_changed","data":{...}}
 *  - {"type":"session.mcp_servers_loaded","data":{...}}
 *  - {"type":"session.skills_loaded","data":{...}}
 *  - {"type":"session.tools_updated","data":{...}}
 *
 *  Conversation events:
 *  - {"type":"user.message","data":{"content":"...","interactionId":"...",...}}
 *  - {"type":"assistant.turn_start","data":{"turnId":"...","interactionId":"..."}}
 *  - {"type":"assistant.reasoning_delta","data":{"reasoningId":"...","deltaContent":"..."}} (streaming)
 *  - {"type":"assistant.message_start","data":{"messageId":"..."}} (streaming)
 *  - {"type":"assistant.message_delta","data":{"messageId":"...","deltaContent":"..."}} (streaming)
 *  - {"type":"assistant.message","data":{"content":"...","model":"...","outputTokens":N,"reasoningText":"...","reasoningOpaque":"...","toolRequests":[...],...}}
 *  - {"type":"assistant.reasoning","data":{"reasoningId":"...","content":"..."}} (ephemeral full reasoning)
 *  - {"type":"assistant.turn_end","data":{"turnId":"..."}}
 *
 *  Terminal event:
 *  - {"type":"result","sessionId":"...","exitCode":0,"usage":{"premiumRequests":N,"totalApiDurationMs":N,...}}
 */
final class RawProcessResult implements RawResultInterface
{
    public function __construct(
        private readonly Process $process,
    ) {
    }

    /**
     * Waits for the process to finish and returns the parsed terminal result event.
     *
     * Merges data from the {@code assistant.message} event (content, model, tokens, reasoning,
     * tool requests) with the {@code result} event (session ID, CLI-level usage) into a single
     * normalized array consumed by {@see ResultConverter::convert()}.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->process->wait();
        $this->assertSuccessful();

        $stdout = rtrim($this->process->getOutput());
        if ('' === $stdout) {
            throw new RuntimeException('GitHub Copilot CLI returned empty output.');
        }

        /** @var array<string, mixed>|null $assistantData */
        $assistantData = null;
        /** @var array<string, mixed>|null $resultEvent */
        $resultEvent = null;

        foreach (self::splitLines($stdout) as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? null;

            if ('assistant.message' === $type && \is_array($event['data'] ?? null)) {
                // Use the last assistant.message in case of multi-turn output.
                $assistantData = $event['data'];
            }

            if ('result' === $type) {
                $resultEvent = $event;
            }
        }

        if (null === $assistantData && null === $resultEvent) {
            throw new RuntimeException('GitHub Copilot CLI stream ended without a terminal result event.');
        }

        $ad = $assistantData ?? [];

        // Normalise tool requests from the Copilot-native format to a portable tool_calls array.
        $toolCalls = [];
        foreach ((array) ($ad['toolRequests'] ?? []) as $req) {
            if (!\is_array($req)) {
                continue;
            }
            $toolCalls[] = [
                'id' => (string) ($req['id'] ?? ''),
                'name' => (string) ($req['name'] ?? $req['toolName'] ?? ''),
                'arguments' => \is_array($req['arguments'] ?? null) ? $req['arguments'] : (\is_array($req['input'] ?? null) ? $req['input'] : []),
            ];
        }

        return [
            'content' => (string) ($ad['content'] ?? ''),
            'reasoningText' => isset($ad['reasoningText']) && '' !== $ad['reasoningText'] ? (string) $ad['reasoningText'] : null,
            'reasoningOpaque' => isset($ad['reasoningOpaque']) && '' !== $ad['reasoningOpaque'] ? (string) $ad['reasoningOpaque'] : null,
            'model' => isset($ad['model']) ? (string) $ad['model'] : null,
            'messageId' => isset($ad['messageId']) ? (string) $ad['messageId'] : null,
            'requestId' => isset($ad['requestId']) ? (string) $ad['requestId'] : null,
            'serviceRequestId' => isset($ad['serviceRequestId']) ? (string) $ad['serviceRequestId'] : null,
            'sessionId' => isset($resultEvent['sessionId']) ? (string) $resultEvent['sessionId'] : null,
            'exitCode' => (int) ($resultEvent['exitCode'] ?? 0),
            // Token usage: outputTokens is on assistant.message.data; input tokens are not reported.
            'usage' => [
                'outputTokens' => isset($ad['outputTokens']) ? (int) $ad['outputTokens'] : null,
            ],
            // CLI-level billing/timing stats from the result event (not token counts).
            // Each field is stored individually so ResultConverter can add them as named metadata.
            'premiumRequests' => isset($resultEvent['usage']['premiumRequests']) ? $resultEvent['usage']['premiumRequests'] : null,
            'totalApiDurationMs' => isset($resultEvent['usage']['totalApiDurationMs']) ? (int) $resultEvent['usage']['totalApiDurationMs'] : null,
            'sessionDurationMs' => isset($resultEvent['usage']['sessionDurationMs']) ? (int) $resultEvent['usage']['sessionDurationMs'] : null,
            'codeChanges' => \is_array($resultEvent['usage']['codeChanges'] ?? null) ? $resultEvent['usage']['codeChanges'] : null,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Yields decoded JSONL events from the process as they arrive.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getDataStream(): \Generator
    {
        $buffer = '';

        while ($this->process->isRunning()) {
            $chunk = $this->process->getIncrementalOutput();

            if ('' === $chunk) {
                usleep(10_000);
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines) ?? '';

            foreach ($lines as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (\is_array($decoded)) {
                    yield $decoded;
                }
            }
        }

        $buffer .= $this->process->getIncrementalOutput();
        foreach (self::splitLines($buffer) as $line) {
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                yield $decoded;
            }
        }

        $this->assertSuccessful();
    }

    public function getObject(): Process
    {
        return $this->process;
    }

    private function assertSuccessful(): void
    {
        if ($this->process->isSuccessful()) {
            return;
        }

        $err = trim($this->process->getErrorOutput());
        throw new RuntimeException(\sprintf(
            'GitHub Copilot CLI process failed (exit %d): %s',
            (int) $this->process->getExitCode(),
            '' !== $err ? $err : 'no stderr output',
        ));
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $stdout): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $stdout)),
            static fn (string $l): bool => '' !== $l,
        ));
    }
}
