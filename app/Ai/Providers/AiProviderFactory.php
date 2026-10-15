<?php

namespace App\Ai\Providers;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Contracts\AiProvider;
use Closure;

/**
 * Maakt de provider voor een agentprofiel. Tests kunnen met `fake()` een vaste provider
 * teruggeven (§0.2: elke externe integratie heeft een fake).
 */
class AiProviderFactory
{
    /**
     * @var (Closure(AiAgentProfile): AiProvider)|null
     */
    private ?Closure $resolver = null;

    public function make(AiAgentProfile $profile): AiProvider
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($profile);
        }

        $baseUrl = $profile->resolvedBaseUrl();
        $options = $profile->extra_options ?? [];

        return match ($profile->provider) {
            ProviderName::Ollama => new OllamaProvider($baseUrl, $profile->api_key, $options),
            ProviderName::Anthropic => new AnthropicProvider($baseUrl, $profile->api_key, $options),
            ProviderName::OpenAi, ProviderName::OpenAiCompatible => new OpenAiCompatibleProvider($baseUrl, $profile->api_key, $options),
        };
    }

    /**
     * @param  AiProvider|(Closure(AiAgentProfile): AiProvider)  $provider
     */
    public function fake(AiProvider|Closure $provider): void
    {
        $this->resolver = $provider instanceof Closure ? $provider : fn (): AiProvider => $provider;
    }
}
