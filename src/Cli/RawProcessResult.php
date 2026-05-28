<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

/**
 * Wraps a Symfony Process running the GitHub Copilot CLI as a RawResultInterface.
 *
 * The CLI with {@code --output-format json} emits JSONL (newline-delimited JSON).
 *
 * Expected event types:
 *  - {"type":"system","subtype":"init","session_id":"...","model":"...","cwd":"..."}
 *  - {"type":"assistant","message":{"content":[{"type":"text","text":"..."}]}}
 *  - {"type":"tool_use","id":"...","name":"...","input":{...}}
 *  - {"type":"tool_result","tool_use_id":"...","content":"..."}
 *  - {"type":"result","subtype":"success","result":"final text","usage":{"input_tokens":N,"output_tokens":N}}
 *  - {"type":"result","subtype":"error_during_generation","error":"..."}
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

        $result = [];
        $toolCalls = [];
        $pendingToolUse = [];

        foreach (self::splitLines($stdout) as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? null;

            if (in_array($type, ['result', 'assistant.message'])) {
                $result += $event['data'] ?? $event;
                continue;
            }

            if ('tool_use' === $type) {
                $callId = (string) ($event['id'] ?? '');
                $name = (string) ($event['name'] ?? '');
                $input = \is_array($event['input'] ?? null) ? $event['input'] : [];
                if ('' !== $callId) {
                    $pendingToolUse[$callId] = ['id' => $callId, 'name' => $name, 'arguments' => $input];
                }
            }

            if ('tool_result' === $type) {
                $callId = (string) ($event['tool_use_id'] ?? '');
                if ('' !== $callId && isset($pendingToolUse[$callId])) {
                    $entry = $pendingToolUse[$callId];
                    $toolCalls[] = $entry;
                    unset($pendingToolUse[$callId]);
                }
            }
        }

        if ([] === $result) {
            throw new RuntimeException('GitHub Copilot CLI stream ended without a terminal "result" event.');
        }

        if ([] !== $toolCalls) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
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
