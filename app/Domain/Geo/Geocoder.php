<?php

namespace App\Domain\Geo;

/**
 * Plaatsnaam → coördinaten (§9.3). Implementatie: PDOK Locatieserver; in tests een fake.
 */
interface Geocoder
{
    /**
     * @return array{lat: float, lng: float, place: string, province: string|null}|null
     */
    public function geocode(string $place): ?array;
}
