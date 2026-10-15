<?php

use App\Http\Controllers\OfferConsentController;
use App\Http\Controllers\OfferFileController;
use Illuminate\Support\Facades\Route;

// De webapp is het Filament-panel op "/" (App\Providers\Filament\AdminPanelProvider).

Route::middleware('signed')->group(function () {
    // Akkoordlink voor de kandidaat (§11.3); werkt zonder in te loggen, 72 uur geldig.
    Route::get('/akkoord/{offer}', [OfferConsentController::class, 'show'])->name('offers.consent');
    Route::post('/akkoord/{offer}', [OfferConsentController::class, 'store'])->name('offers.consent.store');

    // Downloads: signed URL én ingelogd met rechten op de aanbieding (§12.7).
    Route::get('/bestanden/aanbieding/{offer}', [OfferFileController::class, 'show'])->name('offers.file');
    Route::get('/bestanden/aanbieding/{offer}/snapshot.zip', [OfferFileController::class, 'snapshotZip'])->name('offers.snapshot-zip');
});
