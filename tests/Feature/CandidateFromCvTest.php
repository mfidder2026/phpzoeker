<?php

use App\Ai\Agents\CandidateDetailsExtractor;
use App\Ai\Agents\CvImporter;
use App\Ai\Config\AiAgentProfile;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiRunStatus;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Domain\Candidates\Actions\CandidateFromCv;
use App\Domain\Candidates\Enums\CandidateStatus;
use App\Domain\Candidates\Enums\Seniority;
use App\Domain\Candidates\Jobs\ImportMasterCvJob;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Antwoord van `CandidateDetailsExtractor` voor het CV uit `makeCvDocx()`.
 *
 * @param  array<string, mixed>  $overrides
 */
function candidateDetailsJson(array $overrides = []): string
{
    return (string) json_encode([
        'naam' => 'Alex Voorbeeld',
        'functietitel' => 'Senior PHP / Laravel Developer',
        'senioriteit' => 'senior',
        'woonplaats' => 'Rotterdam',
        'postcode' => null,
        'rijbewijs' => true,
        'eigen_vervoer' => null,
        'nationaliteit' => null,
        'talen' => [['code' => 'nl', 'niveau' => 'moedertaal'], ['code' => 'EN', 'niveau' => 'vloeiend'], ['code' => 'Engels', 'niveau' => 'x']],
        'uren_per_week' => 32,
        'uurtarief' => null,
        'beschikbaar_vanaf' => 'per direct',
        'rollen' => ['Laravel Developer', 'PHP Developer'],
        'technologieen' => ['PHP', 'Laravel', 'Kubernetes'],
        ...$overrides,
    ]);
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    aiProfile();
});

it('creates a candidate from an uploaded cv and fills in everything the cv contains', function () {
    Http::fakeSequence('ollama.test/*')
        ->push(ollamaReply(json_encode(profileFixture())))
        ->push(ollamaReply(candidateDetailsJson()));

    $this->actingAs(beheerder());

    $source = sys_get_temp_dir().'/pz-nieuw-'.uniqid().'.docx';
    makeCvDocx($source);
    $upload = UploadedFile::fake()->createWithContent('CV Alex Voorbeeld.docx', (string) file_get_contents($source));

    Livewire::test(CreateCandidate::class)
        ->callAction('createFromCv', ['file' => $upload])
        ->assertHasNoActionErrors();

    $candidate = Candidate::query()->sole();
    $profile = CandidateProfile::query()->where('candidate_id', $candidate->id)->sole();

    expect($candidate->name)->toBe('Alex Voorbeeld')
        ->and($candidate->slug)->toBe('alex-voorbeeld')
        ->and($candidate->job_title)->toBe('Senior PHP / Laravel Developer')
        ->and($candidate->cv_titles)->toBe(['Senior PHP / Laravel Developer'])
        ->and($candidate->seniority)->toBe(Seniority::Senior)
        ->and($candidate->status)->toBe(CandidateStatus::Beschikbaar)
        ->and($candidate->city)->toBe('Rotterdam')
        ->and($candidate->driving_license)->toBeTrue()
        ->and($candidate->own_transport)->toBeNull()
        ->and($candidate->available_from)->toBeNull()
        ->and($candidate->languages)->toBe(['nl' => 'moedertaal', 'en' => 'vloeiend'])
        ->and($candidate->hours_per_week)->toBe(32)
        ->and((float) $candidate->hourly_rate)->toBe(0.0)
        ->and($candidate->email)->toBe('alex@example.com')
        ->and($candidate->phone)->toBe('06-11122233')
        ->and($candidate->birth_date)->toBe('1985-03-14')
        ->and($candidate->searchProfile->roles)->toBe(['Laravel Developer', 'PHP Developer'])
        ->and($candidate->searchProfile->preferred_tech)->toBe(['PHP', 'Laravel', 'Kubernetes'])
        ->and($profile->isApproved())->toBeFalse()
        ->and($profile->source_file_path)->toBe('candidates/'.$candidate->id.'/cv/CV Alex Voorbeeld.docx');

    Storage::disk('local')->assertExists($profile->source_file_path);

    $run = AiRun::query()->sole();
    expect($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->result['ingevuld'])->toContain('naam', 'woonplaats', 'talen', 'rollen');

    // Geboortedatum, telefoon en e-mail gaan in geen van beide aanroepen naar het model (R4).
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request): bool => str_contains($request->body(), '1985')
        || str_contains($request->body(), '11122233')
        || str_contains($request->body(), 'alex@example.com'));
});

it('keeps an inactive placeholder when reading the cv fails', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply('{"naam": "x"}'))]);

    $this->actingAs(beheerder());

    $source = sys_get_temp_dir().'/pz-nieuw-'.uniqid().'.docx';
    makeCvDocx($source);

    Livewire::test(ListCandidates::class)
        ->callAction('createFromCv', ['file' => UploadedFile::fake()->createWithContent('cv.docx', (string) file_get_contents($source))]);

    $candidate = Candidate::query()->sole();

    expect($candidate->status)->toBe(CandidateStatus::Inactief)
        ->and(AiRun::query()->sole()->status)->toBe(AiRunStatus::Failed);
});

it('normalizes the extracted details to safe values', function () {
    $details = CandidateDetailsExtractor::normalize([
        'senioriteit' => 'expert', 'uren_per_week' => 80, 'uurtarief' => '95', 'beschikbaar_vanaf' => '2026-11-01',
        'talen' => [['code' => 'de', 'niveau' => '']], 'rollen' => ['A', 'A', 'B', 'C', 'D', 'E'],
    ]);

    expect($details['senioriteit'])->toBeNull()
        ->and($details['uren_per_week'])->toBeNull()
        ->and($details['uurtarief'])->toBe(95.0)
        ->and($details['beschikbaar_vanaf'])->toBe('2026-11-01')
        ->and($details['talen'])->toBe([['code' => 'de', 'niveau' => null]])
        ->and($details['rollen'])->toBe(['A', 'B', 'C', 'D']);
});

it('does not create a candidate when there is no active ai profile', function () {
    AiAgentProfile::query()->update(['is_active' => false]);
    Http::fake();

    $this->actingAs(beheerder());

    $source = sys_get_temp_dir().'/pz-nieuw-'.uniqid().'.docx';
    makeCvDocx($source);

    Livewire::test(ListCandidates::class)
        ->callAction('createFromCv', ['file' => UploadedFile::fake()->createWithContent('cv.docx', (string) file_get_contents($source))])
        ->assertNotified('Geen actief AI-profiel');

    expect(Candidate::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not leave unsaved changes behind after refilling the form from a finished cv import', function () {
    $user = beheerder();
    $candidate = Candidate::factory()->create(['photo_path' => 'candidates/photos/foto.png', 'phone' => '0612345678', 'birth_date' => '1985-03-14']);
    Storage::disk('local')->put('candidates/photos/foto.png', 'x');
    $run = RunTracker::queue(AiTask::Cv, CvImporter::class, $candidate, $user, ImportMasterCvJob::STEPS);
    $run->markRunning()->finish([]);

    $this->actingAs($user);

    $page = Livewire::withQueryParams(['importRun' => $run->id()])
        ->test(EditCandidate::class, ['record' => $candidate->getRouteKey()])
        ->assertSet('data.phone', '0612345678')
        ->assertSet('data.birth_date', fn (?string $date): bool => str_starts_with((string) $date, '1985-03-14'))
        ->dispatch('ai-run-finished')
        ->assertSet('importRun', null)
        ->assertNotified('CV gelezen');

    expect(md5((string) str(json_encode($page->get('data'), JSON_UNESCAPED_UNICODE))->replace('\\', '')))->toBe($page->get('savedDataHash'));
});

it('renames the photo from the placeholder slug to the real one', function () {
    $fromCv = app(CandidateFromCv::class);
    $candidate = $fromCv->placeholder();
    $candidate->update(['photo_path' => 'candidates/photos/'.$candidate->slug.'.jpeg']);
    Storage::disk('local')->put($candidate->photo_path, 'foto');

    $fromCv->fill($candidate, profileFixture(), CandidateDetailsExtractor::normalize(['naam' => 'Alex Voorbeeld']));

    expect($candidate->refresh()->photo_path)->toBe('candidates/photos/alex-voorbeeld.jpeg');
    Storage::disk('local')->assertExists('candidates/photos/alex-voorbeeld.jpeg');
});
