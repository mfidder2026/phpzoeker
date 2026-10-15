<?php

namespace App\Providers;

use App\Ai\Config\AiAgentProfile;
use App\Ai\Providers\AiProviderFactory;
use App\Ai\Runs\AiRun;
use App\Ai\Support\SecretRedactor;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Domain\Documents\Contracts\PdfConverter;
use App\Domain\Documents\GotenbergPdfConverter;
use App\Livewire\AiGauge;
use App\Livewire\AiRunProgressPanel;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\AiAgentProfilePolicy;
use App\Policies\AiRunPolicy;
use App\Policies\CandidatePolicy;
use App\Policies\CandidateProfilePolicy;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Carbon;
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
        ]);

        Gate::policy(Candidate::class, CandidatePolicy::class);
        Gate::policy(CandidateProfile::class, CandidateProfilePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AiRun::class, AiRunPolicy::class);
        Gate::policy(AiAgentProfile::class, AiAgentProfilePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        Livewire::component('ai-gauge', AiGauge::class);
        Livewire::component('ai-run-progress-panel', AiRunProgressPanel::class);

        if ($this->app->runningInConsole()) {
            // `composer run dev`: de worker moet ook de queues ai, documents en mail verwerken (§16.4).
            DevCommands::except('queue', 'vite');
            DevCommands::artisan('queue:listen --queue=ai,documents,mail,default --tries=1 --timeout=0', 'worker');
        }
    }
}
