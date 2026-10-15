<?php

namespace App\Domain\Sources\Enums;

use Filament\Support\Contracts\HasLabel;

enum SourceType: string implements HasLabel
{
    case Feed = 'feed';
    case MailboxAlert = 'mailbox_alert';
    case Html = 'html';
    case Api = 'api';
    case Manual = 'manual';
    case TenderNed = 'tenderned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Feed => 'Feed (RSS/Atom)',
            self::MailboxAlert => 'Alerts-mailbox',
            self::Html => 'Openbare overzichtspagina',
            self::Api => 'API',
            self::Manual => 'Handmatig',
            self::TenderNed => 'TenderNed',
        };
    }
}
