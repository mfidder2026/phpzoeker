<?php

namespace App\Domain\Offers\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Gebeurtenissen in de tijdlijn van een aanbieding (§11.5).
 */
enum OfferActivityType: string implements HasColor, HasLabel
{
    case Created = 'created';
    case StatusChange = 'status_change';
    case CvGenerated = 'cv_generated';
    case CvApproved = 'cv_approved';
    case MailApproved = 'mail_approved';
    case Consent = 'consent';
    case Sent = 'sent';
    case FollowUp = 'follow_up';
    case ReplyReceived = 'reply_received';
    case Note = 'note';
    case Intake = 'intake';
    case ResponseTerm = 'response_term';
    case Error = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Aangemaakt',
            self::StatusChange => 'Status gewijzigd',
            self::CvGenerated => 'CV gegenereerd',
            self::CvApproved => 'CV goedgekeurd',
            self::MailApproved => 'Mail goedgekeurd',
            self::Consent => 'Akkoord kandidaat',
            self::Sent => 'Verzonden',
            self::FollowUp => 'Opgevolgd',
            self::ReplyReceived => 'Reactie ontvangen',
            self::Note => 'Notitie',
            self::Intake => 'Intake',
            self::ResponseTerm => 'Reactietermijn aangepast',
            self::Error => 'Fout',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Created => 'gray',
            self::StatusChange => 'info',
            self::CvGenerated => 'gray',
            self::CvApproved => 'success',
            self::MailApproved => 'success',
            self::Consent => 'success',
            self::Sent => 'success',
            self::FollowUp => 'warning',
            self::ReplyReceived => 'warning',
            self::Note => 'gray',
            self::Intake => 'warning',
            self::ResponseTerm => 'gray',
            self::Error => 'danger',
        };
    }
}
