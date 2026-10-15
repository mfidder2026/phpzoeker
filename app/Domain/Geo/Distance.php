<?php

namespace App\Domain\Geo;

/**
 * Reisafstand in v1: hemelsbreed (haversine) × 1,3 als benadering van de rijafstand (§9.3).
 */
final class Distance
{
    public const ROAD_FACTOR = 1.3;

    /** Gemiddelde snelheid voor de reistijdschatting (km/u). */
    public const AVERAGE_SPEED = 70;

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function roadKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return round(self::haversineKm($lat1, $lng1, $lat2, $lng2) * self::ROAD_FACTOR, 1);
    }

    public static function travelMinutes(float $roadKm): int
    {
        return (int) round($roadKm / self::AVERAGE_SPEED * 60);
    }
}
