<?php

namespace App\Filament;

/**
 * Groepen van het linkermenu (§15.2), in deze volgorde.
 */
final class NavigationGroup
{
    public const WERK = 'Werk';

    public const AANBIEDINGEN = 'Aanbiedingen';

    public const MENSEN = 'Mensen & relaties';

    public const INZICHT = 'Inzicht';

    public const BEHEER = 'Beheer';

    /**
     * @return list<string>
     */
    public static function ordered(): array
    {
        return [self::WERK, self::AANBIEDINGEN, self::MENSEN, self::INZICHT, self::BEHEER];
    }
}
