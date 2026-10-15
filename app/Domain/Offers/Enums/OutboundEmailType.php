<?php

namespace App\Domain\Offers\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Soort uitgaande mail (§17).
 */
enum OutboundEmailType: string implements HasColor, HasLabel
{
    case Offer = 'offer';
    case FollowUp = 'follow_up';
    case InternalPackage = 'internal_package';
    case Consent = 'consent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Offer => 'Aanbieding',
            self::FollowUp => 'Opvolging',
            self::InternalPackage => 'Intern indieningspakket',
            self::Consent => 'Akkoordverzoek',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Offer => 'info',
            self::FollowUp => 'warning',
            self::InternalPackage => 'gray',
            self::Consent => 'gray',
        };
    }
}
