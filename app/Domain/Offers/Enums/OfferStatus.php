<?php

namespace App\Domain\Offers\Enums;

use App\Domain\Offers\Actions\TransitionOffer;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statusmachine van een aanbieding (§11.1). De toegestane overgangen staan in
 * {@see self::transitions()}; {@see TransitionOffer} dwingt ze af.
 */
enum OfferStatus: string implements HasColor, HasLabel
{
    case Concept = 'concept';
    case CvInReview = 'cv_in_review';
    case MailInReview = 'mail_in_review';
    case WachtOpKandidaat = 'wacht_op_kandidaat';
    case KlaarVoorVerzending = 'klaar_voor_verzending';
    case Verzonden = 'verzonden';
    case ReactieOntvangen = 'reactie_ontvangen';
    case IntakeGepland = 'intake_gepland';
    case Geplaatst = 'geplaatst';
    case Afgewezen = 'afgewezen';
    case GeenReactie = 'geen_reactie';
    case Ingetrokken = 'ingetrokken';

    public function getLabel(): string
    {
        return match ($this) {
            self::Concept => 'Concept',
            self::CvInReview => 'CV in review',
            self::MailInReview => 'Mail in review',
            self::WachtOpKandidaat => 'Wacht op kandidaat',
            self::KlaarVoorVerzending => 'Klaar voor verzending',
            self::Verzonden => 'Verzonden',
            self::ReactieOntvangen => 'Reactie ontvangen',
            self::IntakeGepland => 'Intake gepland',
            self::Geplaatst => 'Geplaatst',
            self::Afgewezen => 'Afgewezen',
            self::GeenReactie => 'Geen reactie',
            self::Ingetrokken => 'Ingetrokken',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Concept, self::CvInReview, self::MailInReview, self::KlaarVoorVerzending => 'gray',
            self::WachtOpKandidaat => 'warning',
            self::Verzonden => 'info',
            self::ReactieOntvangen, self::IntakeGepland => 'warning',
            self::Geplaatst => 'success',
            self::Afgewezen, self::GeenReactie, self::Ingetrokken => 'danger',
        };
    }

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Concept => [self::CvInReview, self::Ingetrokken],
            self::CvInReview => [self::Concept, self::MailInReview, self::Ingetrokken],
            self::MailInReview => [self::CvInReview, self::WachtOpKandidaat, self::KlaarVoorVerzending, self::Ingetrokken],
            self::WachtOpKandidaat => [self::KlaarVoorVerzending, self::MailInReview, self::Ingetrokken],
            self::KlaarVoorVerzending => [self::Verzonden, self::MailInReview, self::Ingetrokken],
            self::Verzonden => [self::ReactieOntvangen, self::IntakeGepland, self::Afgewezen, self::GeenReactie, self::Geplaatst],
            self::ReactieOntvangen => [self::IntakeGepland, self::Afgewezen, self::Geplaatst],
            self::IntakeGepland => [self::Geplaatst, self::Afgewezen],
            self::GeenReactie => [self::ReactieOntvangen, self::IntakeGepland, self::Afgewezen],
            self::Geplaatst, self::Afgewezen, self::Ingetrokken => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->transitions(), true);
    }

    /**
     * Nog niet verstuurd: de aanbieding is in voorbereiding.
     */
    public function isPreparing(): bool
    {
        return in_array($this, [self::Concept, self::CvInReview, self::MailInReview, self::WachtOpKandidaat, self::KlaarVoorVerzending], true);
    }

    /**
     * Verstuurd en nog niet afgerond.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Verzonden, self::ReactieOntvangen, self::IntakeGepland, self::GeenReactie], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Geplaatst, self::Afgewezen, self::Ingetrokken], true);
    }

    /**
     * Statussen na verzending (voor het register en de kengetallen).
     *
     * @return list<string>
     */
    public static function sentValues(): array
    {
        return [self::Verzonden->value, self::ReactieOntvangen->value, self::IntakeGepland->value, self::Geplaatst->value, self::Afgewezen->value, self::GeenReactie->value];
    }
}
