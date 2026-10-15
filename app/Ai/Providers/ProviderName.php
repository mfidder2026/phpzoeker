<?php

namespace App\Ai\Providers;

use Filament\Support\Contracts\HasLabel;

enum ProviderName: string implements HasLabel
{
    case Ollama = 'ollama';
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case OpenAiCompatible = 'openai_compatible';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ollama => 'Ollama (Cloud of lokaal)',
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::OpenAiCompatible => 'OpenAI-compatibel endpoint',
        };
    }

    public function defaultBaseUrl(): string
    {
        return match ($this) {
            self::Ollama => 'https://ollama.com',
            self::Anthropic => 'https://api.anthropic.com',
            self::OpenAi => 'https://api.openai.com',
            self::OpenAiCompatible => '',
        };
    }
}
