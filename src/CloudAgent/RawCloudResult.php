<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\CloudAgent;

use Symfony\AI\Platform\Bridge\Copilot\CloudAgent\Api\RestClient;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Raw result wrapping a GitHub Copilot Cloud Agent task.
 *
 * {@see getData()} blocks by polling the task until it reaches a terminal state.
 * {@see getDataStream()} yields status metadata deltas on each poll cycle.
 *
 * Task states: queued → in_progress → completed | failed | timed_out | cancelled
 */
final class RawCloudResult implements RawResultInterface
{
    private const TERMINAL_STATES = ['completed', 'failed', 'timed_out', 'cancelled'];
    private const DEFAULT_POLL_INTERVAL_US = 3_000_000; // 3 seconds
    private const DEFAULT_MAX_POLLS = 200; // ~10 minutes at 3s

    /** @var array<string, mixed>|null */
    private ?array $cachedData = null;

    public function __construct(
        private readonly RestClient $api,
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $taskId,
        private readonly int $pollIntervalUs = self::DEFAULT_POLL_INTERVAL_US,
        private readonly int $maxPolls = self::DEFAULT_MAX_POLLS,
    ) {
    }

    /**
     * Blocks until the task reaches a terminal state and returns the full task payload.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if (null !== $this->cachedData) {
            return $this->cachedData;
        }

        for ($i = 0; $i < $this->maxPolls; ++$i) {
            $task = $this->api->getTask($this->owner, $this->repo, $this->taskId);

            if (\in_array($task['state'] ?? '', self::TERMINAL_STATES, true)) {
                $this->cachedData = $task;

                return $task;
            }

            usleep($this->pollIntervalUs);
        }

        throw new RuntimeException(\sprintf(
            'GitHub Copilot task "%s" did not reach a terminal state after %d polls (%d seconds).',
            $this->taskId,
            $this->maxPolls,
            (int) ($this->maxPolls * $this->pollIntervalUs / 1_000_000),
        ));
    }

    /**
     * Yields status metadata arrays on each poll until terminal state.
     *
     * Each yielded item is a task snapshot array with at least `id` and `state`.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getDataStream(): \Generator
    {
        for ($i = 0; $i < $this->maxPolls; ++$i) {
            $task = $this->api->getTask($this->owner, $this->repo, $this->taskId);

            yield $task;

            if (\in_array($task['state'] ?? '', self::TERMINAL_STATES, true)) {
                $this->cachedData = $task;

                return;
            }

            usleep($this->pollIntervalUs);
        }

        throw new RuntimeException(\sprintf(
            'GitHub Copilot task "%s" did not reach a terminal state after %d polls.',
            $this->taskId,
            $this->maxPolls,
        ));
    }

    public function getObject(): object
    {
        return (object) ['taskId' => $this->taskId, 'owner' => $this->owner, 'repo' => $this->repo];
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getRepo(): string
    {
        return $this->repo;
    }
}
