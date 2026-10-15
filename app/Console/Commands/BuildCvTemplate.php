<?php

namespace App\Console\Commands;

use App\Domain\Documents\CvTemplateFromSource;
use App\Domain\Documents\CvTemplateRenderer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpOffice\PhpWord\TemplateProcessor;

#[Signature('cv:build-template {source : Pad naar een bestaand Fremen-CV (.docx)} {--language=nl : Taal van de template}')]
#[Description('Maakt de Fremen-CV-template uit een bestaand Fremen-CV (opmaak blijft, inhoud wordt placeholders)')]
class BuildCvTemplate extends Command
{
    public function handle(CvTemplateFromSource $converter): int
    {
        $language = $this->option('language');
        $path = CvTemplateRenderer::templatePath(is_string($language) ? $language : 'nl');

        $source = $this->argument('source');

        if (! is_string($source)) {
            return self::FAILURE;
        }

        $converter->build($source, $path);

        $variables = (new TemplateProcessor($path))->getVariables();
        $missing = array_diff(CvTemplateFromSource::REQUIRED_PLACEHOLDERS, $variables);

        if ($missing !== []) {
            $this->components->error('Ontbrekende placeholders: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->components->info("Template opgeslagen in {$path} (".count($variables).' placeholders).');

        return self::SUCCESS;
    }
}
