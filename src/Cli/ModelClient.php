<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Bridge\Copilot\MessagePayload;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

/**
 * Maps each Platform invocation to the GitHub Copilot CLI ({@code copilot --prompt "..." --output-format json}).
 *
 * Authentication is handled via environment variables:
 *   - GITHUB_TOKEN — personal access token or GitHub App user token
 *   - COPILOT_GITHUB_TOKEN    — copilot-specific override
 *
 * The CLI can also use an existing interactive login session from {@code copilot login}.
 */
final class ModelClient implements ModelClientInterface
{
    private const DEFAULT_BINARY = 'copilot';
    private const DEFAULT_TIMEOUT = 600;

    public function __construct(
        private readonly string $binary = self::DEFAULT_BINARY,
        #[\SensitiveParameter] private readonly ?string $token = null,
        private readonly ?string $workspace = null,
        private readonly bool $yolo = false,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        /** @var list<string> */
        private readonly array $availableTools = [],
        /** @var list<string> */
        private readonly array $excludedTools = [],
        private readonly ?string $configDir = null,
        /** @var list<string> */
        private readonly array $defaultArgs = [],
    ) {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Agent;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!\is_array($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, string given to "%s".', self::class));
        }

        $promptText = MessagePayload::flattenMessages(MessagePayload::requireMessages($payload));
        $command = $this->buildCommand($model, $promptText, $options);

        $env = $this->buildEnv($options);
        $cwd = $this->resolveWorkspace($options);
        $timeout = $this->resolveTimeout($options);

        $process = new Process($command, $cwd, $env, null, $timeout);
        $process->start();

        return new RawProcessResult($process);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function buildCommand(Model $model, string $prompt, array $options): array
    {
        $binary = (string) ($options['copilot_cli_binary'] ?? $this->binary);
        if ('' === trim($binary)) {
            throw new InvalidArgumentException('Option "copilot_cli_binary" cannot be empty.');
        }

        // --prompt and --output-format json are always present for headless mode.
        $command = [$binary, '--prompt', $prompt, '--output-format', 'json', '--stream', ($options['stream'] ?? false) ? 'on' : 'off'];

        $modelName = $model->getName();
        if ('default' !== $modelName) {
            $command[] = '--model';
            $command[] = $modelName;
        }

        if ($options['copilot_yolo'] ?? $this->yolo) {
            $command[] = '--yolo';
        }

        $availableTools = $options['copilot_available_tools'] ?? $this->availableTools;
        if (\is_array($availableTools) && [] !== $availableTools) {
            $command[] = '--available-tools';
            $command[] = implode(',', $availableTools);
        }

        $excludedTools = $options['copilot_excluded_tools'] ?? $this->excludedTools;
        if (\is_array($excludedTools) && [] !== $excludedTools) {
            $command[] = '--excluded-tools';
            $command[] = implode(',', $excludedTools);
        }

        if (isset($options['copilot_effort'])) {
            $effort = (string) $options['copilot_effort'];
            if (!\in_array($effort, ['low', 'medium', 'high'], true)) {
                throw new InvalidArgumentException('Option "copilot_effort" must be "low", "medium", or "high".');
            }
            $command[] = '--effort';
            $command[] = $effort;
        }

        if (isset($options['copilot_agent'])) {
            $command[] = '--agent';
            $command[] = (string) $options['copilot_agent'];
        }

        $configDir = $options['copilot_config_dir'] ?? $this->configDir;
        if (\is_string($configDir) && '' !== $configDir) {
            $command[] = '--config-dir';
            $command[] = $configDir;
        }

        if (isset($options['copilot_resume'])) {
            $resume = $options['copilot_resume'];
            if (true === $resume) {
                $command[] = '--resume';
            } elseif (\is_string($resume) && '' !== $resume) {
                $command[] = '--resume';
                $command[] = $resume;
            } else {
                throw new InvalidArgumentException('Option "copilot_resume" must be true or a session/task ID string.');
            }
        }

        if ($options['copilot_continue'] ?? false) {
            $command[] = '--continue';
        }

        if (isset($options['copilot_session_name'])) {
            $command[] = '--name';
            $command[] = (string) $options['copilot_session_name'];
        }

        if ($options['copilot_experimental'] ?? false) {
            $command[] = '--experimental';
        }

        $extraArgs = $options['copilot_extra_args'] ?? $this->defaultArgs;
        if (!\is_array($extraArgs)) {
            throw new InvalidArgumentException('Option "copilot_extra_args" must be a list of CLI argument strings.');
        }
        foreach ($extraArgs as $arg) {
            if (!\is_string($arg)) {
                throw new InvalidArgumentException('Option "copilot_extra_args" must contain only strings.');
            }
            $command[] = $arg;
        }

        return $command;
    }

    /**
     * Builds the environment array, injecting the GitHub token when provided.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>|null
     */
    private function buildEnv(array $options): ?array
    {
        $token = (string) ($options['copilot_token'] ?? $this->token ?? '');
        if ('' === $token) {
            return null;
        }

        // Set all three common token env vars so the copilot CLI finds the token regardless
        // of which variable it checks first.
        return [
            'GH_TOKEN' => $token,
            'GITHUB_TOKEN' => $token,
            'COPILOT_GITHUB_TOKEN' => $token,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveWorkspace(array $options): ?string
    {
        $workspace = $options['copilot_workspace'] ?? $this->workspace;

        return \is_string($workspace) && '' !== $workspace ? $workspace : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveTimeout(array $options): float
    {
        $timeout = $options['copilot_timeout'] ?? $this->timeout;

        if (!\is_int($timeout) && !\is_float($timeout)) {
            throw new InvalidArgumentException('Option "copilot_timeout" must be an integer or float (seconds).');
        }
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Option "copilot_timeout" must be greater than zero.');
        }

        return (float) $timeout;
    }
}
