<?php

namespace App\Domain\Offers\Actions;

use App\Ai\Agents\CvGroundingJudge;
use App\Ai\Agents\CvTailor;
use App\Ai\Agents\WebResearcher;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Documents\Contracts\PdfConverter;
use App\Domain\Documents\CvTemplateRenderer;
use App\Domain\Matching\Support\ProfileFacts;
use App\Domain\Matching\Support\Technologies;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\TailoredCv;
use App\Domain\Offers\Support\CvDiff;
use App\Domain\Offers\Support\GroundingChecker;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * "CV herschrijven" in de wizard (§11.2 stap 2, §12, §15.4): opdracht analyseren →
 * optioneel website-onderzoek → CV herschrijven → grounding-check → Word en PDF.
 */
class GenerateTailoredCv
{
    /**
     * @var list<array{key: string, label: string, weight: int, optional?: bool}>
     */
    public const STEPS = [
        ['key' => 'analyse', 'label' => 'Opdracht en eisen analyseren', 'weight' => 5],
        ['key' => 'research', 'label' => 'Website opdrachtgever onderzoeken', 'weight' => 15, 'optional' => true],
        ['key' => 'tailor', 'label' => 'CV herschrijven', 'weight' => 55],
        ['key' => 'grounding', 'label' => 'Grounding-check', 'weight' => 15],
        ['key' => 'files', 'label' => 'Word- en PDF-bestand maken', 'weight' => 10],
    ];

    public function __construct(
        private readonly CvTailor $tailor,
        private readonly CvGroundingJudge $judge,
        private readonly WebResearcher $researcher,
        private readonly CvTemplateRenderer $renderer,
        private readonly PdfConverter $pdf,
        private readonly PromptRepository $prompts,
        private readonly TransitionOffer $transitions,
    ) {}

    public static function queueRun(Offer $offer, ?User $user): TrackedRun
    {
        return RunTracker::queue(
            task: AiTask::Cv,
            agent: CvTailor::class,
            subject: $offer->assignment,
            startedBy: $user,
            steps: self::STEPS,
            subjectLabel: 'CV herschrijven — '.$offer->candidate->name.' → '.Str::limit($offer->assignment->title, 60),
            promptVersion: app(PromptRepository::class)->label(CvTailor::PROMPT),
        );
    }

    public function handle(Offer $offer, TrackedRun $run, ?User $user): TailoredCv
    {
        $offer->loadMissing(['candidate.activeProfile', 'assignment', 'match', 'organization']);
        $run->markRunning();
        $technologies = Technologies::fromSettings();
        $facts = ProfileFacts::for($offer->candidate, $technologies) ?? throw new RuntimeException("{$offer->candidate->name} heeft geen goedgekeurd master-profiel.");
        $language = $offer->assignment->language === 'en' ? 'en' : 'nl';

        try {
            $run->step('analyse')->begin();
            $assessment = $offer->match?->assessment;
            $allowedTitles = array_values(array_filter((array) ($offer->candidate->cv_titles ?? [])));
            $run->step('analyse')->complete();

            $research = $this->research($offer, $run);

            $run->throwIfCancelled();
            $run->step('tailor')->begin();
            $content = $this->tailor->tailor($run, $offer->assignment, $facts, $assessment, $allowedTitles, $language, $research);
            $run->step('tailor')->complete();

            $checker = new GroundingChecker($technologies);
            $restored = $checker->restoreFixedFields($content, $facts->profile);
            $content = $restored['content'];

            $run->throwIfCancelled();
            $run->step('grounding')->begin();
            $report = $this->groundingReport($checker, $content, $facts, $allowedTitles, $run);
            $report['restored'] = $restored['restored'];
            $run->step('grounding')->complete();

            $cv = $offer->tailoredCvs()->create([
                'version' => ((int) $offer->tailoredCvs()->max('version')) + 1,
                'language' => $language,
                'profile_version' => $facts->profileVersion(),
                'content' => collect($content)->except('changes')->all(),
                'changes' => $content['changes'] ?? [],
                'diff_summary' => CvDiff::summarize($facts->profile, $content),
                'grounding_report' => $report,
                'grounding_passed' => $report['passed'],
                'model' => $run->profile?->modelFor(AiTask::Cv),
                'prompt_version' => $this->prompts->label(CvTailor::PROMPT),
                'ai_run_id' => $run->id(),
            ]);

            $run->step('files')->begin();
            $this->renderFiles($cv);
            $run->step('files')->complete();
        } catch (Throwable $exception) {
            $run->fail($exception);

            throw $exception;
        }

        if ($offer->status === OfferStatus::Concept) {
            $this->transitions->handle($offer, OfferStatus::CvInReview, $user);
        }

        TransitionOffer::record($offer, OfferActivityType::CvGenerated, $user, [
            'text' => "CV v{$cv->version} gegenereerd".($cv->grounding_passed ? '' : ' — grounding-check niet gehaald'),
            'version' => $cv->version,
        ]);

        $run->finish(['tailored_cv_id' => $cv->id, 'version' => $cv->version, 'grounding_passed' => $cv->grounding_passed]);

        return $cv;
    }

    /**
     * Grounding-rapport na een handmatige bewerking (zonder nieuwe tailor-aanroep).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function regroundEdited(Offer $offer, array $content, TrackedRun $run): array
    {
        $technologies = Technologies::fromSettings();
        $facts = ProfileFacts::for($offer->candidate, $technologies) ?? throw new RuntimeException('Geen goedgekeurd master-profiel.');
        $run->markRunning();
        $run->step('grounding')->begin();
        $report = $this->groundingReport(new GroundingChecker($technologies), $content, $facts, array_values(array_filter((array) ($offer->candidate->cv_titles ?? []))), $run);
        $run->step('grounding')->complete();
        $run->finish(['grounding_passed' => $report['passed']]);

        return $report;
    }

    /**
     * Maakt (opnieuw) DOCX en PDF van een CV-versie.
     */
    public function renderFiles(TailoredCv $cv): void
    {
        $offer = $cv->offer;
        $name = self::fileBaseName($offer, $cv->version);
        $directory = "offers/{$offer->id}/cv";
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory);

        $docx = $this->renderer->render($offer->candidate, $cv->content, $disk->path("{$directory}/{$name}.docx"), (array) ($offer->cv_options ?? []), $cv->language);
        $pdfPath = null;

        try {
            $pdfPath = $this->pdf->convert($docx, $disk->path("{$directory}/{$name}.pdf"));
        } catch (RuntimeException $exception) {
            report($exception);
        }

        $cv->forceFill([
            'docx_path' => "{$directory}/{$name}.docx",
            'pdf_path' => $pdfPath !== null ? "{$directory}/{$name}.pdf" : null,
        ])->save();
    }

    /**
     * `CV {Naam} - Fremen - {referentie of eindklant} - v{n}` (§12.7).
     */
    public static function fileBaseName(Offer $offer, int $version): string
    {
        $label = $offer->assignment->reference ?: ($offer->assignment->client_name ?: 'opdracht '.$offer->assignment_id);
        $safe = fn (string $value): string => trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', '-', $value));

        return $safe("CV {$offer->candidate->name} - Fremen - {$label} - v{$version}");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function research(Offer $offer, TrackedRun $run): ?array
    {
        $organization = $offer->assignment->clientOrganization ?? $offer->organization;

        if ($organization === null || ! ($run->profile->web_research_enabled ?? false) || blank($organization->website)) {
            $run->step('research')->skip();

            return null;
        }

        $cached = $this->researcher->cached($organization);

        if ($cached !== null) {
            $run->step('research')->skip();

            return $cached;
        }

        $run->step('research')->begin();

        try {
            $research = $this->researcher->research($run, $organization);
            $run->step('research')->complete();

            return $research;
        } catch (AiProviderException $exception) {
            $run->step('research')->failStep($exception->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<string>  $allowedTitles
     * @return array{passed: bool, checks: list<array{check: string, passed: bool, detail: string}>, unsupported_claims: list<array{claim: string, location: string, reason: string}>, judge_error: string|null}
     */
    private function groundingReport(GroundingChecker $checker, array $content, ProfileFacts $facts, array $allowedTitles, TrackedRun $run): array
    {
        $checks = $checker->check($content, $facts, $allowedTitles);
        $claims = [];
        $judgeError = null;

        try {
            $claims = $this->judge->judge($run, $facts, $content);
        } catch (AiProviderException $exception) {
            // Zonder oordeel van de rechter kan het CV niet worden goedgekeurd.
            $judgeError = $exception->getMessage();
        }

        $passed = collect($checks)->every(fn (array $check): bool => $check['passed']) && $claims === [] && $judgeError === null;

        return ['passed' => $passed, 'checks' => $checks, 'unsupported_claims' => $claims, 'judge_error' => $judgeError];
    }
}
