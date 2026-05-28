<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\CloudAgent;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * Resolves model names for the GitHub Copilot Cloud Agent Tasks API.
 *
 * Supported models (may change over time per GitHub's policy):
 *   claude-sonnet-4.6, claude-opus-4.6, gpt-5.2-codex, gpt-5.3-codex, gpt-5.4,
 *   claude-sonnet-4.5, claude-opus-4.5
 *
 * Use "default" to let GitHub's auto model-selection apply.
 */
final class ModelCatalog extends AbstractModelCatalog
{
    public function __construct()
    {
        $this->models = [];
    }

    public function getModel(string $modelName): Model
    {
        if ('' === trim($modelName)) {
            throw new InvalidArgumentException('Model name cannot be empty.');
        }

        $parsed = $this->parseModelName($modelName);

        return new Agent(
            $parsed['name'],
            [
                Capability::INPUT_MESSAGES,
                Capability::OUTPUT_TEXT,
            ],
            $parsed['options'],
        );
    }
}
