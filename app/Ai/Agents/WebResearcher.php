<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Ai\Tools\FetchPageTool;
use App\Domain\Relations\Models\Organization;
use App\Settings\PrivacySettings;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Website-onderzoek naar de opdrachtgever (§18.1): leest een paar openbare pagina's van de
 * eigen website van de organisatie via `FetchPageTool` (allowlist, robots.txt, rate limit,
 * cache) en vat ze samen met bronvermelding. Geen inloggen of formulieren (R3). Het resultaat
 * wordt per organisatie 30 dagen bewaard en is nooit een bron van feiten over de kandidaat (R2).
 */
class WebResearcher
{
    public const PROMPT = 'web-researcher';

    /** Maximaal aantal pagina's per taak. */
    public const MAX_PAGES = 3;

    /** @var list<string> */
    private const PATHS = ['/', '/over-ons', '/organisatie'];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
        private readonly FetchPageTool $pages,
        private readonly PrivacySettings $privacy,
    ) {}

    /**
     * Bewaard onderzoek dat nog geldig is, of null.
     *
     * @return array<string, mixed>|null
     */
    public function cached(Organization $organization): ?array
    {
        $research = $organization->getAttribute('research');
        $researchedAt = $organization->getAttribute('researched_at');

        if (! is_array($research) || $researchedAt === null || now()->subDays($this->privacy->web_research_days)->greaterThan($researchedAt)) {
            return null;
        }

        return $research;
    }

    /**
     * @return array<string, mixed>|null null als er geen pagina's te lezen zijn
     *
     * @throws AiProviderException
     */
    public function research(TrackedRun $run, Organization $organization): ?array
    {
        $host = parse_url((string) $organization->website, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $allowed = array_values(array_unique([...($run->profile->web_allowed_domains ?? []), $host]));
        $base = 'https://'.$host;
        $pages = [];

        foreach (array_slice(self::PATHS, 0, self::MAX_PAGES) as $path) {
            $url = rtrim($base, '/').$path;
            $run->detail($url);

            try {
                $page = $this->pages->fetch($url, $allowed);
            } catch (RuntimeException) {
                continue;
            }

            if (mb_strlen($page['text']) > 200) {
                $pages[] = ['url' => $page['url'], 'titel' => $page['title'], 'tekst' => mb_substr($page['text'], 0, 6000)];
            }
        }

        $run->detail(null);

        if ($pages === []) {
            return null;
        }

        $data = $this->gateway->structured(
            run: $run,
            task: AiTask::Research,
            system: $this->prompts->get(self::PROMPT),
            user: "<organisatie>{$organization->name}</organisatie>\n\n<paginas>\n".json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n</paginas>",
            schema: [
                'type' => 'object',
                'required' => ['samenvatting', 'feiten', 'technologieen', 'relevante_punten_voor_aanbieding'],
                'properties' => [
                    'samenvatting' => ['type' => 'string'],
                    'feiten' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['tekst', 'bron_url'], 'properties' => ['tekst' => ['type' => 'string'], 'bron_url' => ['type' => 'string']]]],
                    'technologieen' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'relevante_punten_voor_aanbieding' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            validator: function (array $data) use ($pages): ?string {
                $validator = Validator::make($data, [
                    'samenvatting' => ['required', 'string'],
                    'feiten' => ['present', 'array'],
                    'feiten.*.tekst' => ['required', 'string'],
                    'feiten.*.bron_url' => ['required', 'string', 'in:'.implode(',', array_column($pages, 'url'))],
                    'technologieen' => ['present', 'array'],
                    'relevante_punten_voor_aanbieding' => ['present', 'array'],
                ]);

                return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
            },
            userInitiated: true,
        );

        $organization->forceFill(['research' => $data, 'researched_at' => now()])->save();

        return $data;
    }
}
