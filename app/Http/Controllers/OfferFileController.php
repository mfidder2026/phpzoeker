<?php

namespace App\Http\Controllers;

use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Models\OfferSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Downloads van CV's, pakketten en snapshots (§12.7): alleen via een signed URL én voor een
 * ingelogde gebruiker die de aanbieding mag zien. Paden buiten de map van de aanbieding worden
 * geweigerd.
 */
class OfferFileController extends Controller
{
    public static function url(Offer $offer, string $path, ?string $name = null, bool $inline = false): string
    {
        return URL::temporarySignedRoute('offers.file', now()->addHours(2), array_filter([
            'offer' => $offer->id,
            'path' => $path,
            'name' => $name,
            'inline' => $inline ? 1 : null,
        ]));
    }

    public static function snapshotZipUrl(Offer $offer): string
    {
        return URL::temporarySignedRoute('offers.snapshot-zip', now()->addHours(2), ['offer' => $offer->id]);
    }

    public function show(Request $request, Offer $offer): BinaryFileResponse|StreamedResponse
    {
        abort_unless($request->user()?->can('view', $offer) ?? false, 403);

        $path = $request->string('path')->toString();
        abort_unless(str_starts_with($path, "offers/{$offer->id}/") && ! str_contains($path, '..'), 404);

        $disk = Storage::disk(OfferSnapshot::DISK);
        abort_unless($disk->exists($path), 404);

        $name = $request->string('name')->toString() ?: basename($path);

        return $request->boolean('inline')
            ? response()->file($disk->path($path), ['Content-Disposition' => 'inline; filename="'.addslashes($name).'"', 'X-Content-Type-Options' => 'nosniff'])
            : $disk->download($path, $name);
    }

    /**
     * "Download alles (zip)" van een snapshot (§11.5).
     */
    public function snapshotZip(Request $request, Offer $offer): BinaryFileResponse
    {
        abort_unless($request->user()?->can('view', $offer) ?? false, 403);

        $snapshot = $offer->snapshot ?? abort(404);
        $disk = Storage::disk(OfferSnapshot::DISK);
        $zipPath = tempnam(sys_get_temp_dir(), 'snapshot');
        $zip = new ZipArchive;
        $zip->open((string) $zipPath, ZipArchive::OVERWRITE);

        if ($snapshot->eml_path !== null && $disk->exists($snapshot->eml_path)) {
            $zip->addFile($disk->path($snapshot->eml_path), 'aanbieding.eml');
        }

        foreach ($snapshot->attachments ?? [] as $file) {
            if ($disk->exists($file['path'])) {
                $zip->addFile($disk->path($file['path']), $file['name']);
            }
        }

        $zip->addFromString('snapshot.json', (string) json_encode([
            'captured_at' => $snapshot->captured_at->toIso8601String(),
            'sha256' => $snapshot->sha256,
            'email_headers' => $snapshot->email_headers,
            'offer_data' => $snapshot->offer_data,
            'assessment' => $snapshot->assessment,
            'assignment_data' => $snapshot->assignment_data,
            'provenance' => $snapshot->provenance,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return response()->download((string) $zipPath, "Aanbieding {$offer->id} - snapshot.zip")->deleteFileAfterSend();
    }
}
