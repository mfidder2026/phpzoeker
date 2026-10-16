<?php

namespace App\Ai\Agents;

use App\Ai\AiGateway;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\TrackedRun;
use App\Ai\Support\PromptRepository;
use App\Domain\Candidates\Enums\Seniority;

/**
 * Haalt de vaste kandidaatgegevens (functietitel, senioriteit, woonplaats, talen, zoekprofiel, …) uit
 * een CV, voor een nieuwe kandidaat die vanuit het CV wordt aangemaakt. Geboortedatum, telefoon en
 * e-mail haalt de app zelf uit het CV; die gaan nooit naar het taalmodel (R4).
 */
class CandidateDetailsExtractor
{
    public const PROMPT = 'candidate-details';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @return array{naam: string|null, functietitel: string|null, senioriteit: string|null, woonplaats: string|null, postcode: string|null, rijbewijs: bool|null, eigen_vervoer: bool|null, nationaliteit: string|null, talen: list<array{code: string, niveau: string|null}>, uren_per_week: int|null, uurtarief: float|null, beschikbaar_vanaf: string|null, rollen: list<string>, technologieen: list<string>}
     *
     * @throws AiProviderException
     */
    public function extract(TrackedRun $run, string $scrubbedCvText): array
    {
        $data = $this->gateway->structured(
            run: $run,
            task: AiTask::Cv,
            system: $this->prompts->get(self::PROMPT),
            user: "<cv>\n{$scrubbedCvText}\n</cv>",
            schema: self::jsonSchema(),
        );

        return self::normalize($data);
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableBoolean = ['type' => ['boolean', 'null']];
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'required' => ['naam', 'functietitel', 'senioriteit', 'woonplaats', 'postcode', 'rijbewijs', 'eigen_vervoer', 'nationaliteit', 'talen', 'uren_per_week', 'uurtarief', 'beschikbaar_vanaf', 'rollen', 'technologieen'],
            'properties' => [
                'naam' => $nullableString,
                'functietitel' => $nullableString,
                'senioriteit' => ['type' => ['string', 'null'], 'enum' => [...array_map(fn (Seniority $level): string => $level->value, Seniority::cases()), null]],
                'woonplaats' => $nullableString,
                'postcode' => $nullableString,
                'rijbewijs' => $nullableBoolean,
                'eigen_vervoer' => $nullableBoolean,
                'nationaliteit' => $nullableString,
                'talen' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['code', 'niveau'],
                    'properties' => ['code' => ['type' => 'string'], 'niveau' => $nullableString],
                ]],
                'uren_per_week' => ['type' => ['integer', 'null']],
                'uurtarief' => ['type' => ['number', 'null']],
                'beschikbaar_vanaf' => $nullableString,
                'rollen' => $stringList,
                'technologieen' => $stringList,
            ],
        ];
    }

    /**
     * Maakt de uitkomst type-vast: onbekende of ongeldige waarden worden null of een lege lijst.
     *
     * @param  array<string, mixed>  $data
     * @return array{naam: string|null, functietitel: string|null, senioriteit: string|null, woonplaats: string|null, postcode: string|null, rijbewijs: bool|null, eigen_vervoer: bool|null, nationaliteit: string|null, talen: list<array{code: string, niveau: string|null}>, uren_per_week: int|null, uurtarief: float|null, beschikbaar_vanaf: string|null, rollen: list<string>, technologieen: list<string>}
     */
    public static function normalize(array $data): array
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $boolean = fn (string $key): ?bool => is_bool($data[$key] ?? null) ? $data[$key] : null;
        $strings = fn (string $key): array => array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => is_string($value) ? trim($value) : '', (array) ($data[$key] ?? [])),
            fn (string $value): bool => $value !== '',
        )));

        $languages = [];

        foreach ((array) ($data['talen'] ?? []) as $language) {
            $code = is_array($language) && is_string($language['code'] ?? null) ? strtolower(trim($language['code'])) : '';

            if (preg_match('/^[a-z]{2}$/', $code) === 1) {
                $languages[] = ['code' => $code, 'niveau' => is_string($language['niveau'] ?? null) && trim($language['niveau']) !== '' ? trim($language['niveau']) : null];
            }
        }

        $hours = is_int($data['uren_per_week'] ?? null) && $data['uren_per_week'] >= 1 && $data['uren_per_week'] <= 60 ? $data['uren_per_week'] : null;
        $rate = is_numeric($data['uurtarief'] ?? null) && (float) $data['uurtarief'] > 0 ? round((float) $data['uurtarief'], 2) : null;
        $availableFrom = $string('beschikbaar_vanaf');

        return [
            'naam' => $string('naam'),
            'functietitel' => $string('functietitel'),
            'senioriteit' => Seniority::tryFrom((string) $string('senioriteit'))?->value,
            'woonplaats' => $string('woonplaats'),
            'postcode' => $string('postcode') !== null ? mb_substr((string) $string('postcode'), 0, 16) : null,
            'rijbewijs' => $boolean('rijbewijs'),
            'eigen_vervoer' => $boolean('eigen_vervoer'),
            'nationaliteit' => $string('nationaliteit'),
            'talen' => $languages,
            'uren_per_week' => $hours,
            'uurtarief' => $rate,
            'beschikbaar_vanaf' => $availableFrom !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $availableFrom) === 1 ? $availableFrom : null,
            'rollen' => array_slice($strings('rollen'), 0, 4),
            'technologieen' => array_slice($strings('technologieen'), 0, 15),
        ];
    }
}
