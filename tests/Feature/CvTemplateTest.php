<?php

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Documents\CvTemplateFromSource;
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

    foreach (CvTemplateFromSource::REQUIRED_PLACEHOLDERS as $placeholder) {
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
        ->toContain('Voorbeeldbank')
        ->toContain('Bedrijfskritische PKI-omgeving')
        ->toContain('PROFESSIONEEL PROFIEL')
        ->toContain('OPLEIDINGEN &amp; CERTIFICATEN')
        ->toContain('Kubernetes (basis)')
        ->toContain('Klantportaal  ·  2023')
        ->toContain('Scrum Master')
        ->toContain('ICT-beheerder')
        ->toContain('Nederlands: moedertaal')
        ->toContain('Contact via Fremen')
        ->not->toContain('${')
        ->not->toContain('AANSLUITING OP DE OPDRACHT')
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

    expect(docxText($path))->toContain('AANSLUITING OP DE OPDRACHT')->toContain('Minimaal 5 jaar Laravel');

    @unlink($path);
});

it('renders profile paragraphs, extra sections and custom section headings', function () {
    $candidate = Candidate::factory()->create();
    $path = sys_get_temp_dir().'/pz-render-'.uniqid().'.docx';
    $profile = [...profileFixture(),
        'profieltekst' => 'Eerste alinea.
Tweede alinea.',
        'aanvullende_secties' => [['kop' => 'AI-augmented engineering', 'items' => ['Agentic workflows', 'Prompt engineering', 'AI code review']]],
        'sectiekoppen' => ['opleidingen' => 'Opleidingen & vakontwikkeling', 'eerdere_ervaring' => null, 'talen' => null],
        'voetregel' => 'Profiel gericht op Laravel.',
    ];

    app(CvTemplateRenderer::class)->render($candidate, $profile, $path);
    $text = docxText($path);

    expect($text)
        ->toContain('Eerste alinea.
')
        ->toContain('Tweede alinea.')
        ->toContain('AI-AUGMENTED ENGINEERING')
        ->toContain('Agentic workflows')
        ->toContain('AI code review')
        ->toContain('OPLEIDINGEN &amp; VAKONTWIKKELING')
        ->toContain('Profiel gericht op Laravel.')
        ->not->toContain('${');

    @unlink($path);
});

it('contains no personal data from the source cv', function () {
    $zip = new ZipArchive;
    $zip->open(CvTemplateRenderer::templatePath());
    $content = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $content .= (string) $zip->getFromIndex($i);
    }
    $zip->close();

    expect($content)->not->toContain('Zande')->not->toContain('@fremen.app')->not->toContain('KPN')
        ->and(collect(range(0, 0))->isNotEmpty())->toBeTrue();
});

it('produces a valid document when texts contain special characters', function () {
    $candidate = Candidate::factory()->create();
    $path = sys_get_temp_dir().'/pz-render-'.uniqid().'.docx';
    $profile = profileFixture();
    $profile['werkervaring'][0]['bullets'][] = 'Koppelingen <REST> & "SOAP"';
    $profile['overige_vaardigheden'][] = 'Python & SQL';

    app(CvTemplateRenderer::class)->render($candidate, $profile, $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    expect((new DOMDocument)->loadXML($xml))->toBeTrue()
        ->and($xml)->toContain('Koppelingen &lt;REST&gt; &amp; ')
        ->and($xml)->toContain('Python &amp; SQL');

    @unlink($path);
});

it('keeps the education order of the profile', function () {
    $candidate = Candidate::factory()->create();
    $path = sys_get_temp_dir().'/pz-render-'.uniqid().'.docx';
    $profile = [...profileFixture(), 'opleidingen' => [
        ['periode' => '2000', 'naam' => 'ECDL', 'instelling' => null, 'status' => 'behaald'],
        ['periode' => '1997 – 2001', 'naam' => 'MAVO 4', 'instelling' => null, 'status' => 'behaald'],
    ], 'certificaten' => []];

    app(CvTemplateRenderer::class)->render($candidate, $profile, $path);
    $text = docxText($path);

    expect(strpos($text, 'ECDL'))->toBeLessThan(strpos($text, 'MAVO 4'));

    @unlink($path);
});

it('writes the nationality as in the Fremen cv', function () {
    expect(CvTemplateRenderer::nationality('Nederlands'))->toBe('Nederlandse nationaliteit')
        ->and(CvTemplateRenderer::nationality('Belgisch'))->toBe('Belgische nationaliteit')
        ->and(CvTemplateRenderer::nationality('NL'))->toBe('Nationaliteit: NL');
});
