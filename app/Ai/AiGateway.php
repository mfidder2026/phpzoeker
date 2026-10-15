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
    /** Fouten waarbij het fallbackprofiel wordt geprobeerd (niet bij een ongeldige sleutel of verzoek). */
    public const FALLBACK_ERRORS = ['unreachable', 'unavailable', 'timeout', 'rate_limited'];

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

        $response = $this->send($run, $task, $profile, $request);
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

        $retry = $this->send($run, $task, $profile, $retryRequest);
        $this->budget->notifyThresholds($profile);

        $data = $retry->json();
        $error = $validator === null ? null : $validator($data);

        if ($error !== null) {
            throw AiProviderException::invalidOutput("De AI-output is ook na een tweede poging ongeldig: {$error}");
        }

        return $data;
    }

    /**
     * Aanroep bij de provider; is die niet bereikbaar of overbelast en is er een fallbackprofiel
     * (FR-AI9), dan gaat dezelfde vraag naar dat profiel, met het model dat daar voor de taak staat.
     *
     * @throws AiProviderException
     */
    private function send(TrackedRun $run, AiTask $task, AiAgentProfile $profile, AiRequest $request): AiResponse
    {
        try {
            $response = $this->providers->make($profile)->chat($request);
            $run->recordUsage($response);

            return $response;
        } catch (AiProviderException $exception) {
            // Een verbindingstest test het profiel zelf; daar geen fallback.
            $fallback = $task !== AiTask::ConnectionTest && in_array($exception->errorCode, self::FALLBACK_ERRORS, true) ? AiAgentProfile::fallbackFor($profile) : null;

            if ($fallback === null || $fallback->modelFor($task) === '') {
                throw $exception;
            }

            $run->detail('Fallbackprofiel: '.$fallback->name.' ('.$exception->getMessage().')');
            $response = $this->providers->make($fallback)->chat(new AiRequest(
                model: $fallback->modelFor($task),
                system: $request->system,
                messages: $request->messages,
                schema: $request->schema,
                maxOutputTokens: $request->maxOutputTokens,
                timeoutSeconds: $fallback->timeout_seconds,
                temperature: $request->temperature,
            ));
            $run->recordUsage($response, $fallback);
            $run->detail(null);

            return $response;
        }
    }

    private function profileFor(TrackedRun $run): AiAgentProfile
    {
        return $run->profile ?? throw AiProviderException::notConfigured('Er is geen actief AI-agentprofiel. Stel dit in via Instellingen → AI-agent.');
    }
}
