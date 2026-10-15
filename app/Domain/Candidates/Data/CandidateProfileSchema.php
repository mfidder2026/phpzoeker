<?php

namespace App\Domain\Candidates\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Schema van het master-profiel (Bijlage D): JSON Schema voor het taalmodel en
 * Laravel-validatie voor de output en de handmatige editor.
 */
final class CandidateProfileSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $stringList = ['type' => 'array', 'items' => $string];

        return [
            'type' => 'object',
            'required' => ['naam', 'cv_titel', 'tagline', 'profieltekst', 'kernexpertise', 'werkervaring', 'projecten', 'opleidingen', 'certificaten', 'eerdere_ervaring', 'talen', 'overige_vaardigheden'],
            'properties' => [
                'naam' => $string,
                'cv_titel' => $string,
                'tagline' => $string,
                'profieltekst' => $string,
                'kernexpertise' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['kop', 'items'],
                    'properties' => [
                        'kop' => $string,
                        'items' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'required' => ['naam'],
                            'properties' => [
                                'naam' => $string,
                                'niveau' => ['type' => ['string', 'null'], 'enum' => ['basis', 'gevorderd', 'expert', null]],
                                'sinds' => $nullableString,
                            ],
                        ]],
                    ],
                ]],
                'werkervaring' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['id', 'van', 'tot', 'functie', 'organisatie', 'bullets'],
                    'properties' => [
                        'id' => $string,
                        'van' => $string,
                        'tot' => $string,
                        'functie' => $string,
                        'organisatie' => $string,
                        'via' => $nullableString,
                        'subtitel' => $nullableString,
                        'bullets' => $stringList,
                        'technologieen' => $stringList,
                    ],
                ]],
                'projecten' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['id', 'naam', 'omschrijving'],
                    'properties' => [
                        'id' => $string,
                        'naam' => $string,
                        'omschrijving' => $string,
                        'technologieen' => $stringList,
                        'periode' => $nullableString,
                    ],
                ]],
                'opleidingen' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['periode', 'naam', 'status'],
                    'properties' => [
                        'periode' => $string,
                        'naam' => $string,
                        'instelling' => $nullableString,
                        'status' => ['type' => 'string', 'enum' => ['behaald', 'gevolgd', 'lopend']],
                    ],
                ]],
                'certificaten' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['jaar', 'naam'],
                    'properties' => ['jaar' => $string, 'naam' => $string],
                ]],
                'eerdere_ervaring' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['periode', 'functie', 'organisatie'],
                    'properties' => [
                        'periode' => $string,
                        'functie' => $string,
                        'organisatie' => $string,
                        'omschrijving' => $nullableString,
                    ],
                ]],
                'talen' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['taal', 'niveau'],
                    'properties' => ['taal' => $string, 'niveau' => $string],
                ]],
                'overige_vaardigheden' => $stringList,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'naam' => ['required', 'string'],
            'cv_titel' => ['present', 'nullable', 'string'],
            'tagline' => ['present', 'nullable', 'string'],
            'profieltekst' => ['required', 'string'],
            'kernexpertise' => ['present', 'array'],
            'kernexpertise.*.kop' => ['required', 'string'],
            'kernexpertise.*.items' => ['present', 'array'],
            'kernexpertise.*.items.*.naam' => ['required', 'string'],
            'kernexpertise.*.items.*.niveau' => ['nullable', 'in:basis,gevorderd,expert'],
            'kernexpertise.*.items.*.sinds' => ['nullable', 'regex:/^\d{4}$/'],
            'werkervaring' => ['present', 'array'],
            'werkervaring.*.id' => ['required', 'string', 'distinct'],
            'werkervaring.*.van' => ['required', 'regex:/^\d{4}(-\d{2})?$/'],
            'werkervaring.*.tot' => ['required', 'regex:/^(\d{4}(-\d{2})?|heden)$/'],
            'werkervaring.*.functie' => ['required', 'string'],
            'werkervaring.*.organisatie' => ['required', 'string'],
            'werkervaring.*.bullets' => ['present', 'array'],
            'werkervaring.*.bullets.*' => ['string'],
            'werkervaring.*.technologieen' => ['nullable', 'array'],
            'projecten' => ['present', 'array'],
            'projecten.*.id' => ['required', 'string', 'distinct'],
            'projecten.*.naam' => ['required', 'string'],
            'projecten.*.omschrijving' => ['present', 'nullable', 'string'],
            'projecten.*.technologieen' => ['nullable', 'array'],
            'opleidingen' => ['present', 'array'],
            'opleidingen.*.naam' => ['required', 'string'],
            'opleidingen.*.status' => ['required', 'in:behaald,gevolgd,lopend'],
            'certificaten' => ['present', 'array'],
            'certificaten.*.naam' => ['required', 'string'],
            'eerdere_ervaring' => ['present', 'array'],
            'talen' => ['present', 'array'],
            'talen.*.taal' => ['required', 'string'],
            'overige_vaardigheden' => ['present', 'array'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> foutmeldingen (leeg = geldig)
     */
    public static function validate(array $data): array
    {
        $validator = Validator::make($data, self::rules());

        return $validator->fails() ? array_values($validator->errors()->all()) : [];
    }

    /**
     * Vocabulaire van technologieën en vaardigheden in het profiel, genormaliseerd. Basis voor
     * de deterministische grounding-check (§12.3).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function skillsVocabulary(array $data): array
    {
        $terms = [];

        foreach ((array) ($data['kernexpertise'] ?? []) as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                $terms[] = (string) ($item['naam'] ?? '');
            }
        }

        foreach (['werkervaring', 'projecten'] as $section) {
            foreach ((array) ($data[$section] ?? []) as $entry) {
                foreach ((array) ($entry['technologieen'] ?? []) as $technology) {
                    $terms[] = (string) $technology;
                }
            }
        }

        foreach ((array) ($data['overige_vaardigheden'] ?? []) as $skill) {
            $terms[] = (string) $skill;
        }

        $normalized = array_map(fn (string $term): string => Str::of($term)->lower()->squish()->toString(), $terms);

        $unique = array_values(array_unique(array_filter($normalized, fn (string $term): bool => $term !== '')));
        sort($unique);

        return $unique;
    }

    /**
     * Leeg profiel als startpunt voor de handmatige editor.
     *
     * @return array<string, mixed>
     */
    public static function empty(string $name = ''): array
    {
        return [
            'naam' => $name,
            'cv_titel' => '',
            'tagline' => '',
            'profieltekst' => '',
            'kernexpertise' => [],
            'werkervaring' => [],
            'projecten' => [],
            'opleidingen' => [],
            'certificaten' => [],
            'eerdere_ervaring' => [],
            'talen' => [],
            'overige_vaardigheden' => [],
        ];
    }
}
