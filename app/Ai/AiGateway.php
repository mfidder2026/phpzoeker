<?php

namespace App\Ai;

use App\Ai\Budget\AiBudgetGuard;
use App\Ai\Config\AiAgentProfile;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Providers\AiProviderFactory;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\AiRequest;
use App\Ai\Support\AiResponse;

/**
 * Enige doorgang naar een taalmodel. Vereist een `TrackedRun`, zodat er geen AI-aanroep
 * buiten het `AiRun`-mechanisme kan bestaan (R10).
 */
class AiGateway
{
    public function __construct(
        private readonly AiProviderFactory $providers,
        private readonly AiBudgetGuard $budget,
    ) {}

    /**
     * @param  array<string, mixed>|null  $schema
     *
     * @throws AiProviderException
     */
    public function chat(
        TrackedRun $run,
        AiTask $task,
        string $system,
        string $user,
        ?array $schema = null,
        bool $userInitiated = true,
        ?string $model = null,
        ?int $maxOutputTokens = null,
    ): AiResponse {
        $profile = $this->profileFor($run);

        $this->budget->ensureAllowed($task, $userInitiated, $profile);

        $model ??= $profile->modelFor($task);

        if ($model === '') {
            throw AiProviderException::notConfigured("Er is geen model ingesteld voor de taak '{$task->getLabel()}'.");
        }

        $request = AiRequest::make(
            model: $model,
            system: $system,
            user: $user,
            schema: $schema,
            maxOutputTokens: $maxOutputTokens ?? $profile->maxOutputTokensFor($task),
            timeoutSeconds: $profile->timeout_seconds,
        );

        $response = $this->providers->make($profile)->chat($request);
        $run->recordUsage($response);
        $this->budget->notifyThresholds($profile);

        return $response;
    }

    /**
     * Gestructureerde aanroep: valideert de JSON en doet bij ongeldige output één retry met de
     * foutmelding erbij (§18.1).
     *
     * @param  array<string, mixed>  $schema
     * @param  (callable(array<string, mixed>): ?string)|null  $validator  geeft een foutmelding of null
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    public function structured(
        TrackedRun $run,
        AiTask $task,
        string $system,
        string $user,
        array $schema,
        ?callable $validator = null,
        bool $userInitiated = true,
        ?string $model = null,
        ?int $maxOutputTokens = null,
    ): array {
        $response = $this->chat($run, $task, $system, $user, $schema, $userInitiated, $model, $maxOutputTokens);

        $error = null;

        try {
            $data = $response->json();
            $error = $validator === null ? null : $validator($data);
        } catch (AiProviderException $exception) {
            $error = $exception->getMessage();
            $data = [];
        }

        if ($error === null) {
            return $data;
        }

        $profile = $this->profileFor($run);
        $retryRequest = AiRequest::make(
            model: $model ?? $profile->modelFor($task),
            system: $system,
            user: $user,
            schema: $schema,
            maxOutputTokens: $maxOutputTokens ?? $profile->maxOutputTokensFor($task),
            timeoutSeconds: $profile->timeout_seconds,
        )->withExtraUserMessage($response->text, "Je antwoord is ongeldig: {$error}\nGeef opnieuw alleen het JSON-object, gecorrigeerd volgens het schema.");

        $retry = $this->providers->make($profile)->chat($retryRequest);
        $run->recordUsage($retry);
        $this->budget->notifyThresholds($profile);

        $data = $retry->json();
        $error = $validator === null ? null : $validator($data);

        if ($error !== null) {
            throw AiProviderException::invalidOutput("De AI-output is ook na een tweede poging ongeldig: {$error}");
        }

        return $data;
    }

    private function profileFor(TrackedRun $run): AiAgentProfile
    {
        return $run->profile ?? throw AiProviderException::notConfigured('Er is geen actief AI-agentprofiel. Stel dit in via Instellingen → AI-agent.');
    }
}
