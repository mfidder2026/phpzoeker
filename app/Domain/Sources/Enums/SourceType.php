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
    case Alert = 'alert';

    public function getLabel(): string
    {
        return match ($this) {
            self::Feed => 'Feed (RSS/Atom)',
            self::MailboxAlert => 'Alerts-mailbox',
            self::Html => 'Openbare overzichtspagina',
            self::Api => 'API',
            self::Manual => 'Handmatig',
            self::TenderNed => 'TenderNed',
            self::Alert => 'Job alert → aanvragen-mailbox',
        };
    }

    /**
     * Haalt de app deze bron zelf op? Job alerts komen binnen via de aanvragen-mailbox;
     * voor API en TenderNed is nog geen connector gebouwd.
     */
    public function canFetch(): bool
    {
        return in_array($this, [self::Feed, self::MailboxAlert, self::Html, self::Manual], true);
    }

    /**
     * Brontypen die de app zelf ophaalt bij een externe website (voor "Nu zoeken").
     *
     * @return list<string>
     */
    public static function searchable(): array
    {
        return [self::Feed->value, self::MailboxAlert->value, self::Html->value];
    }
}
