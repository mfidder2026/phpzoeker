<?php

namespace App\Ai\Support;

use RuntimeException;

/**
 * Leest versiebeheerde prompts uit `resources/prompts/{naam}.{versie}.md` (§0.2, §18.2).
 * De actieve versie per prompt staat in `config/phpzoeker.php`.
 */
class PromptRepository
{
    public function version(string $name): string
    {
        return (string) config("phpzoeker.prompts.{$name}", 'v1');
    }

    public function get(string $name, ?string $version = null): string
    {
        $version ??= $this->version($name);
        $path = resource_path("prompts/{$name}.{$version}.md");

        if (! is_file($path)) {
            throw new RuntimeException("Prompt '{$name}' versie '{$version}' bestaat niet.");
        }

        return (string) file_get_contents($path);
    }

    /**
     * Versielabel zoals het bij elke AI-uitkomst wordt opgeslagen, bijv. `cv-importer.v1`.
     */
    public function label(string $name): string
    {
        return $name.'.'.$this->version($name);
    }
}
