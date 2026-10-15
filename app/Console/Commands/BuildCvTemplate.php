<?php

namespace App\Console\Commands;

use App\Domain\Documents\CvTemplateBuilder;
use App\Domain\Documents\CvTemplateRenderer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cv:build-template {--language=nl : Taal van de template}')]
#[Description('Bouwt de Fremen-CV-template met placeholders (Bijlage F)')]
class BuildCvTemplate extends Command
{
    public function handle(CvTemplateBuilder $builder): int
    {
        $language = $this->option('language');
        $path = CvTemplateRenderer::templatePath(is_string($language) ? $language : 'nl');
        $builder->build($path);

        $this->components->info("Template opgeslagen in {$path}");

        return self::SUCCESS;
    }
}
