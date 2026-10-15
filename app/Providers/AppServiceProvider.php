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
use App\Domain\Geo\Geocoder;
use App\Domain\Geo\PdokGeocoder;
use App\Domain\Matching\Listeners\MatchIngestedAssignment;
use App\Domain\Matching\Listeners\RematchChangedCandidate;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Relations\Models\Organization;
use App\Domain\Sources\Mailbox\ImapMailboxClient;
use App\Domain\Sources\Mailbox\MailboxClient;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Livewire\AiGauge;
use App\Livewire\AiRunProgressPanel;
use App\Livewire\MicrosoftLoginPanel;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\AiAgentProfilePolicy;
use App\Policies\AiRunPolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\CandidatePolicy;
use App\Policies\CandidateProfilePolicy;
use App\Policies\MatchPolicy;
use App\Policies\SourcePolicy;
use App\Policies\UserPolicy;
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
        $this->app->bind(Geocoder::class, fn (): Geocoder => new PdokGeocoder((string) config('phpzoeker.pdok_url')));
        $this->app->bind(PdfConverter::class, fn (): PdfConverter => new GotenbergPdfConverter((string) config('phpzoeker.gotenberg_url')));
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

        Event::listen(AssignmentIngested::class, MatchIngestedAssignment::class);
        Event::listen(CandidateValuesChanged::class, RematchChangedCandidate::class);

        Livewire::component('ai-gauge', AiGauge::class);
        Livewire::component('ai-run-progress-panel', AiRunProgressPanel::class);
        Livewire::component('microsoft-login-panel', MicrosoftLoginPanel::class);

        if ($this->app->runningInConsole()) {
            // `composer run dev`: de worker moet ook de queues ai, documents en mail verwerken (§16.4).
            DevCommands::except('queue', 'vite');
            DevCommands::artisan('queue:listen --queue=ai,ingest,documents,mail,default --tries=1 --timeout=0', 'worker');
        }
    }
}
