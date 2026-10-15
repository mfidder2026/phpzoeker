<?php

namespace App\Ai\Contracts;

use App\Ai\Exceptions\AiProviderException;
use App\Ai\Support\AiRequest;
use App\Ai\Support\AiResponse;

/**
 * Eén taalmodelprovider. Implementaties praten rechtstreeks met de HTTP-API van de provider,
 * zodat sleutelafscherming (R9), tokentelling en foutvertaling in eigen hand blijven.
 */
interface AiProvider
{
    /**
     * @throws AiProviderException
     */
    public function chat(AiRequest $request): AiResponse;
}
