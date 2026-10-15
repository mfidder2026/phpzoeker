<?php

namespace App\Ai\Support;

use App\Ai\Exceptions\AiProviderException;

final readonly class AiResponse
{
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}

    /**
     * Haalt het JSON-object uit de modeloutput. Sommige modellen zetten JSON tussen ```-fences
     * of schrijven er een zin omheen; dat wordt hier afgevangen.
     *
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    public function json(): array
    {
        $text = trim($this->text);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw AiProviderException::invalidOutput('Het model gaf geen geldig JSON-object terug.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
