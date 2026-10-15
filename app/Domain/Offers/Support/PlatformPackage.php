<?php

namespace App\Domain\Offers\Support;

use App\Domain\Offers\Models\Offer;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Indieningspakket voor een platform (§13.6): getailord CV (PDF + DOCX), motivatie, antwoorden
 * per eis (binnen het maximum aantal tekens), tarief en beschikbaarheid.
 */
class PlatformPackage
{
    public function __construct(private readonly OfferMailComposer $composer) {}

    /**
     * @param  array{intro: string, bullets: list<string>, eis_antwoorden: list<array{id: string, antwoord: string}>}  $text
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function build(Offer $offer, array $text): array
    {
        $disk = Storage::disk('local');
        $directory = "offers/{$offer->id}/package";
        $disk->makeDirectory($directory);
        $path = "{$directory}/indieningspakket.zip";
        $zip = new ZipArchive;

        if ($zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Het indieningspakket kon niet worden gemaakt.');
        }

        $cv = $offer->latestCv;
        $base = "CV {$offer->candidate->name} - Fremen";

        foreach (['pdf_path' => 'pdf', 'docx_path' => 'docx'] as $attribute => $extension) {
            $file = $cv?->getAttribute($attribute);

            if (is_string($file) && $disk->exists($file)) {
                $zip->addFile($disk->path($file), "{$base}.{$extension}");
            }
        }

        $requirements = collect($offer->assignment->requirements ?? [])->keyBy('id');
        $answers = array_map(
            fn (array $answer): string => $answer['id'].' — '.($requirements->get($answer['id'])['tekst'] ?? '')."\n".$answer['antwoord'],
            $text['eis_antwoorden'],
        );

        $zip->addFromString('motivatie.txt', $text['intro']."\n\n".implode("\n", array_map(fn (string $bullet): string => '• '.$bullet, $text['bullets']))."\n");
        $zip->addFromString('antwoorden-per-eis.txt', implode("\n\n", $answers)."\n");
        $zip->addFromString('tarief-en-beschikbaarheid.txt', implode("\n", $this->composer->practicalLines($offer))."\n");
        $zip->close();

        return ['path' => $path, 'name' => "Indieningspakket {$offer->candidate->name}.zip", 'mime' => 'application/zip', 'size' => $disk->size($path)];
    }
}
