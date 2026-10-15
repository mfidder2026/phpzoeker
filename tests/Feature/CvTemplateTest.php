<?php

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Documents\CvTemplateBuilder;
use App\Domain\Documents\CvTemplateRenderer;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Leest de platte tekst uit document.xml van een DOCX.
 */
function docxText(string $path): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    return strip_tags(str_replace('</w:p>', "\n", $xml));
}

it('contains every placeholder from appendix F in a single run', function () {
    $variables = (new TemplateProcessor(CvTemplateRenderer::templatePath()))->getVariables();

    foreach (CvTemplateBuilder::REQUIRED_PLACEHOLDERS as $placeholder) {
        expect($variables)->toContain($placeholder);
    }
});

it('renders the master profile into the Fremen template', function () {
    $candidate = Candidate::factory()->create(['name' => 'Alex Voorbeeld', 'city' => 'Rotterdam', 'birth_date' => '1985-03-14']);
    $path = sys_get_temp_dir().'/pz-render-'.uniqid().'.docx';

    app(CvTemplateRenderer::class)->render($candidate, profileFixture(), $path);
    $text = docxText($path);

    expect($text)
        ->toContain('ALEX VOORBEELD')
        ->toContain('Senior PHP / Laravel Developer')
        ->toContain('Ontwikkeling van het certificatenportaal in Laravel.')
        ->toContain('Verwerken van pentestbevindingen &amp; releases via OTAP.')
        ->toContain('Maatwerkplatformen en klantportalen.')
        ->toContain('03-2021 – heden')
        ->toContain('Voorbeeldbank (via Fremen)')
        ->toContain('Kubernetes (basis)')
        ->toContain('Klantportaal · 2023')
        ->toContain('Scrum Master')
        ->toContain('ICT-beheerder')
        ->toContain('Nederlands (moedertaal)')
        ->toContain('Contact via Fremen')
        ->not->toContain('${')
        ->not->toContain('Aansluiting op de opdracht')
        ->not->toContain('1985');

    @unlink($path);
});

it('only shows the birth date when explicitly enabled', function () {
    $candidate = Candidate::factory()->create(['birth_date' => '1985-03-14']);
    $path = sys_get_temp_dir().'/pz-render-'.uniqid().'.docx';

    app(CvTemplateRenderer::class)->render($candidate, profileFixture(), $path, ['show_birth_date' => true]);

    expect(docxText($path))->toContain('14-03-1985');

    @unlink($path);
});

it('renders the fit section for a tailored cv', function () {
    $candidate = Candidate::factory()->create();
    $path = sys_get_temp_dir().'/pz-render-'.uniqid().'.docx';
    $profile = [...profileFixture(), 'aansluiting_op_opdracht' => [
        ['eis_id' => 'E1', 'eis' => 'Minimaal 5 jaar Laravel', 'invulling' => 'Sinds 2015 Laravel (WE1, WE2).', 'bron_in_profiel' => 'werkervaring[0]'],
    ]];

    app(CvTemplateRenderer::class)->render($candidate, $profile, $path);

    expect(docxText($path))->toContain('Aansluiting op de opdracht')->toContain('Minimaal 5 jaar Laravel');

    @unlink($path);
});
