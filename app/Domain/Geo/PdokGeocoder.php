<?php

namespace App\Domain\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PDOK Locatieserver (gratis, Nederlands): woonplaats → centroïde en provincie. Resultaten
 * worden per plaatsnaam 30 dagen gecachet; een mislukte opzoeking blokkeert de pipeline niet.
 */
class PdokGeocoder implements Geocoder
{
    public function __construct(private readonly string $baseUrl) {}

    public function geocode(string $place): ?array
    {
        $place = trim((string) preg_replace('/\s*[-–(].*$/u', '', $place));

        if ($place === '' || mb_strlen($place) < 2) {
            return null;
        }

        $key = 'pdok:'.Str::slug($place);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var array{lat: float, lng: float, place: string, province: string|null} $cached */
            return $cached;
        }

        try {
            $response = Http::timeout(10)->get(rtrim($this->baseUrl, '/').'/free', [
                'q' => $place,
                'fq' => 'type:woonplaats',
                'rows' => 1,
                'fl' => 'woonplaatsnaam,provincienaam,centroide_ll',
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $doc = $response->successful() ? ($response->json('response.docs.0') ?? null) : null;

        if (! is_array($doc) || preg_match('/POINT\(([\d.\-]+) ([\d.\-]+)\)/', (string) ($doc['centroide_ll'] ?? ''), $m) !== 1) {
            return null;
        }

        $result = [
            'lat' => (float) $m[2],
            'lng' => (float) $m[1],
            'place' => (string) ($doc['woonplaatsnaam'] ?? $place),
            'province' => isset($doc['provincienaam']) ? (string) $doc['provincienaam'] : null,
        ];

        Cache::put($key, $result, now()->addDays(30));

        return $result;
    }
}
