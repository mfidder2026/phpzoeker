<?php

use App\Ai\Agents\CvImporter;
use App\Ai\Runs\AiRunStatus;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Domain\Candidates\Actions\ApproveMasterProfile;
use App\Domain\Candidates\Events\CandidateValuesChanged;
use App\Domain\Candidates\Jobs\ImportMasterCvJob;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Domain\Documents\DocxTextExtractor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

it('extracts paragraphs, bullets and table cells from a docx', function () {
    $path = sys_get_temp_dir().'/pz-cv-'.uniqid().'.docx';
    makeCvDocx($path);

    $text = app(DocxTextExtractor::class)->extract($path);

    expect($text)->toContain('ALEX VOORBEELD')
        ->toContain('• Ontwikkeling van het certificatenportaal in Laravel.')
        ->toContain('2021 – heden | Senior Developer bij Voorbeeldbank');

    @unlink($path);
});

it('imports a master cv as an unapproved draft without sending personal data to the model', function () {
    Storage::fake('local');
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(json_encode(profileFixture()), 2000, 800))]);
    aiProfile();
    $admin = beheerder();
    $candidate = Candidate::factory()->create(['birth_date' => '1985-03-14', 'phone' => '06-11122233']);

    makeCvDocx(Storage::disk('local')->path('candidates/'.$candidate->id.'/cv/cv.docx'));

    $run = RunTracker::queue(AiTask::Cv, CvImporter::class, $candidate, $admin, ImportMasterCvJob::STEPS, 'CV importeren');
    ImportMasterCvJob::dispatch($run->id(), $candidate->id, 'candidates/'.$candidate->id.'/cv/cv.docx');

    $run = $run->run->refresh();
    $profile = CandidateProfile::query()->where('candidate_id', $candidate->id)->firstOrFail();

    expect($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->input_tokens)->toBe(2000)
        ->and($profile->version)->toBe(1)
        ->and($profile->isApproved())->toBeFalse()
        ->and($profile->data['werkervaring'])->toHaveCount(2)
        ->and($profile->skills_vocabulary)->toContain('laravel', 'kubernetes')
        ->and($profile->prompt_version)->toBe('cv-importer.v2')
        ->and($candidate->refresh()->active_profile_id)->toBeNull();

    Http::assertSent(function ($request): bool {
        $body = $request->body();

        return str_contains($body, 'ALEX VOORBEELD')
            && ! str_contains($body, '1985')
            && ! str_contains($body, '11122233')
            && ! str_contains($body, 'alex@example.com');
    });
});

it('marks the run as failed when the model output stays invalid', function () {
    Storage::fake('local');
    Http::fake(['ollama.test/*' => Http::response(ollamaReply('{"naam": "x"}'))]);
    aiProfile();
    $candidate = Candidate::factory()->create();
    makeCvDocx(Storage::disk('local')->path('cv.docx'));

    $run = RunTracker::queue(AiTask::Cv, CvImporter::class, $candidate, null, ImportMasterCvJob::STEPS);
    ImportMasterCvJob::dispatch($run->id(), $candidate->id, 'cv.docx');

    expect($run->run->refresh()->status)->toBe(AiRunStatus::Failed)
        ->and($run->run->error_code)->toBe('invalid_output')
        ->and(CandidateProfile::query()->count())->toBe(0);
});

it('approves a draft, makes it active and freezes it', function () {
    Event::fake([CandidateValuesChanged::class]);
    $admin = beheerder();
    $candidate = Candidate::factory()->create();
    $profile = $candidate->profiles()->create(['version' => 1, 'data' => profileFixture()]);

    $errors = app(ApproveMasterProfile::class)->handle($profile, $admin);

    expect($errors)->toBe([])
        ->and($profile->refresh()->approved_by)->toBe($admin->id)
        ->and($candidate->refresh()->active_profile_id)->toBe($profile->id);

    Event::assertDispatched(CandidateValuesChanged::class);

    $data = $profile->data;
    $data['profieltekst'] = 'Gewijzigd';

    expect(fn () => $profile->update(['data' => $data]))->toThrow(DomainException::class);
});

it('refuses to approve an invalid profile', function () {
    $candidate = Candidate::factory()->create();
    $profile = $candidate->profiles()->create(['version' => 1, 'data' => ['naam' => 'X']]);

    expect(app(ApproveMasterProfile::class)->handle($profile, null))->not->toBe([])
        ->and($profile->refresh()->isApproved())->toBeFalse();
});

it('takes over phone, e-mail and photo locally without sending them to the model', function () {
    Storage::fake('local');
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(json_encode(profileFixture())))]);
    aiProfile();
    $candidate = Candidate::factory()->create(['phone' => null, 'email' => null, 'photo_path' => null]);

    $path = Storage::disk('local')->path('cv-met-foto.docx');
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('ALEX VOORBEELD');
    $section->addText('Utrecht | +31 (0)6 12345678 | alex@example.com | Rijbewijs B');
    $image = sys_get_temp_dir().'/pz-foto-'.uniqid().'.png';
    imagepng(imagecreatetruecolor(20, 20), $image);
    $section->addImage($image);
    IOFactory::createWriter($word, 'Word2007')->save($path);

    $run = RunTracker::queue(AiTask::Cv, CvImporter::class, $candidate, null, ImportMasterCvJob::STEPS);
    ImportMasterCvJob::dispatch($run->id(), $candidate->id, 'cv-met-foto.docx');

    $candidate->refresh();

    expect($candidate->phone)->toBe('+31 (0)6 12345678')
        ->and($candidate->email)->toBe('alex@example.com')
        ->and($candidate->photo_path)->toStartWith('candidates/photos/')
        ->and(Storage::disk('local')->exists((string) $candidate->photo_path))->toBeTrue()
        ->and($run->run->refresh()->result['overgenomen'])->toBe(['telefoon', 'e-mail', 'pasfoto']);

    Http::assertSent(fn ($request): bool => ! str_contains($request->body(), '12345678') && ! str_contains($request->body(), 'alex@example.com'));

    @unlink($image);
});
