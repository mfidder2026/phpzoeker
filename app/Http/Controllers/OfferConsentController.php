<?php

namespace App\Http\Controllers;

use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Enums\ConsentStatus;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Models\Offer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Akkoordpagina voor de kandidaat (§11.3): een signed URL die 72 uur geldig is. De keuze
 * wordt pas vastgelegd na een klik op de knop (een linkvoorbeeld in een mailprogramma mag niets
 * veranderen).
 */
class OfferConsentController extends Controller
{
    public function show(Offer $offer): View
    {
        return view('offers.consent', ['offer' => $offer->load(['candidate', 'assignment']), 'done' => null]);
    }

    public function store(Request $request, Offer $offer, OfferWorkflow $workflow): View
    {
        $decision = $request->string('decision')->toString() === 'akkoord' ? ConsentStatus::Akkoord : ConsentStatus::Geweigerd;

        if ($offer->status === OfferStatus::WachtOpKandidaat) {
            $workflow->registerConsent($offer, $decision, null, 'Via de akkoordlink'.($request->filled('note') ? ': '.mb_substr($request->string('note')->toString(), 0, 250) : ''));
        }

        return view('offers.consent', ['offer' => $offer->refresh()->load(['candidate', 'assignment']), 'done' => $offer->consent_status]);
    }
}
