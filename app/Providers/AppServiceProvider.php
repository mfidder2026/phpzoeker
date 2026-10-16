<?php

namespace App\Providers;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Providers\AiProviderFactory;
use App\Ai\Runs\AiRun;
use App\Ai\Support\SecretRedactor;
use App\Domain\Assignments\Events\AssignmentIngested;
use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Events\CandidateValuesChanged;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Domain\Documents\Contracts\PdfConverter;
use App\Domain\Documents\GotenbergPdfConverter;
use App\Domain\Documents\WordPdfConverter;
use App\Domain\Geo\Geocoder;
use App\Domain\Geo\PdokGeocoder;
use App\Domain\Matching\Listeners\MatchIngestedAssignment;
use App\Domain\Matching\Listeners\RematchChangedCandidate;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Offers\Mailers\MicrosoftGraphMailer;
use App\Domain\Offers\Mailers\OutboundMailer;
use App\Domain\Offers\Mailers\SmtpOutboundMailer;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Offers\Models\TailoredCv;
use App\Domain\Relations\Models\Contact;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Mailbox\ImapMailboxClient;
use App\Domain\Sources\Mailbox\MailboxClient;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Livewire\AiGauge;
use App\Livewire\AiRunProgressPanel;
use App\Livewire\MicrosoftLoginPanel;
use App\Livewire\MicrosoftSignInPanel;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\AiAgentProfilePolicy;
use App\Policies\AiRunPolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\CandidatePolicy;
use App\Policies\CandidateProfilePolicy;
use App\Policies\MatchPolicy;
use App\Policies\OfferPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\SourcePolicy;
use App\Policies\UserPolicy;
use App\Settings\MailSettings;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiProviderFactory::class);
        $this->app->bind(MailboxClient::class, ImapMailboxClient::class);
        // Uitgaande aanbiedingsmail (§13.2): Microsoft 365 (Graph) of SMTP, volgens Instellingen → Mail.
        $this->app->bind(OutboundMailer::class, fn (): OutboundMailer => app(MailSettings::class)->provider === 'graph'
            ? app(MicrosoftGraphMailer::class)
            : app(SmtpOutboundMailer::class));
        $this->app->bind(Geocoder::class, fn (): Geocoder => new PdokGeocoder((string) config('phpzoeker.pdok_url')));
        $this->app->bind(PdfConverter::class, function (): PdfConverter {
            $gotenberg = new GotenbergPdfConverter((string) config('phpzoeker.gotenberg_url'));

            return match (config('phpzoeker.pdf_converter')) {
                'word' => new WordPdfConverter,
                'gotenberg' => $gotenberg,
                // auto: Word op een Windows-machine zonder Docker (D3), anders Gotenberg.
                default => PHP_OS_FAMILY === 'Windows' ? new WordPdfConverter : $gotenberg,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('nl');

        SecretRedactor::register(config('phpzoeker.ai_bootstrap.api_key'));
        SecretRedactor::register(config('mail.mailers.smtp.password'));

        Relation::enforceMorphMap([
            'user' => User::class,
            'candidate' => Candidate::class,
            'candidate_profile' => CandidateProfile::class,
            'ai_agent_profile' => AiAgentProfile::class,
            'ai_run' => AiRun::class,
            'source' => Source::class,
            'raw_posting' => RawPosting::class,
            'assignment' => Assignment::class,
            'organization' => Organization::class,
            'match' => CandidateMatch::class,
            'offer' => Offer::class,
            'tailored_cv' => TailoredCv::class,
            'outbound_email' => OutboundEmail::class,
            'offer_snapshot' => OfferSnapshot::class,
            'contact' => Contact::class,
        ]);

        Gate::policy(Candidate::class, CandidatePolicy::class);
        Gate::policy(CandidateProfile::class, CandidateProfilePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AiRun::class, AiRunPolicy::class);
        Gate::policy(AiAgentProfile::class, AiAgentProfilePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(Source::class, SourcePolicy::class);
        Gate::policy(CandidateMatch::class, MatchPolicy::class);
        Gate::policy(Offer::class, OfferPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);

        Event::listen(AssignmentIngested::class, MatchIngestedAssignment::class);
        Event::listen(CandidateValuesChanged::class, RematchChangedCandidate::class);

        Livewire::component('ai-gauge', AiGauge::class);
        Livewire::component('ai-run-progress-panel', AiRunProgressPanel::class);
        Livewire::component('microsoft-login-panel', MicrosoftLoginPanel::class);
        Livewire::component('microsoft-sign-in-panel', MicrosoftSignInPanel::class);

        if ($this->app->runningInConsole()) {
            // `composer run dev`: de worker moet ook de queues ai, documents en mail verwerken (§16.4).
            DevCommands::except('queue', 'vite');
            DevCommands::artisan('queue:listen --queue=ai,ingest,documents,mail,default --tries=1 --timeout=0', 'worker');
        }
    }
}
