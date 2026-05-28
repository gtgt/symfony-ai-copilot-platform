<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Copilot\Cli;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * Resolves model names for the GitHub Copilot CLI ({@code copilot --model}; use {@code default} for CLI auto model).
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
                Capability::OUTPUT_STREAMING,
                Capability::TOOL_CALLING,
            ],
            $parsed['options'],
        );
    }
}
