<?php

namespace App\Domain\Offers\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\Holidays\Holidays;

/**
 * Werkdagen: maandag t/m vrijdag zonder Nederlandse feestdagen (§11.6). Feestdagen komen uit
 * spatie/holidays (NL): nieuwjaarsdag, Pasen, Koningsdag, Bevrijdingsdag (lustrum), Hemelvaart,
 * Pinksteren en Kerst.
 */
final class Workdays
{
    /** @var array<int, array<string, true>> */
    private static array $holidays = [];

    public static function isWorkday(CarbonInterface $date): bool
    {
        return ! $date->isWeekend() && ! isset(self::holidaysFor((int) $date->format('Y'))[$date->format('Y-m-d')]);
    }

    /**
     * Telt N werkdagen op na `$from` (de dag zelf telt niet mee) en zet de tijd.
     */
    public static function add(CarbonInterface $from, int $days, string $time = '17:00'): CarbonImmutable
    {
        $date = CarbonImmutable::parse($from->format('Y-m-d'), $from->getTimezone());
        $added = 0;

        while ($added < $days) {
            $date = $date->addDay();

            if (self::isWorkday($date)) {
                $added++;
            }
        }

        [$hour, $minute] = array_map('intval', explode(':', $time) + [1 => '0']);

        return $date->setTime($hour, $minute);
    }

    /**
     * Aantal werkdagen tussen twee momenten (de begindag telt niet mee), met een halve dag
     * precisie voor het moment van de dag.
     */
    public static function between(CarbonInterface $from, CarbonInterface $until): float
    {
        if ($until->lessThanOrEqualTo($from)) {
            return 0.0;
        }

        $date = CarbonImmutable::parse($from->format('Y-m-d'), $from->getTimezone());
        $end = CarbonImmutable::parse($until->format('Y-m-d'), $until->getTimezone());
        $days = 0;

        while ($date->lessThan($end)) {
            $date = $date->addDay();

            if (self::isWorkday($date)) {
                $days++;
            }
        }

        return (float) $days;
    }

    /**
     * @return array<string, true>
     */
    private static function holidaysFor(int $year): array
    {
        if (! isset(self::$holidays[$year])) {
            self::$holidays[$year] = [];

            try {
                foreach (Holidays::for('nl', $year)->get() as $holiday) {
                    self::$holidays[$year][$holiday->date->format('Y-m-d')] = true;
                }
            } catch (\Throwable) {
                // Zonder feestdagenlijst gelden alleen de weekenden.
            }
        }

        return self::$holidays[$year];
    }
}
