<?php

namespace App\Domain\Matching\Data;

use Illuminate\Support\Facades\Validator;

/**
 * Schema van de matchbeoordeling (Bijlage C): JSON Schema voor het model en Laravel-validatie
 * van de output (§18.1).
 */
final class AssessmentSchema
{
    public const VERDICTS = ['voldoet', 'deels', 'voldoet_niet', 'onbekend'];

    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $item = [
            'type' => 'object',
            'required' => ['id', 'oordeel', 'bewijs', 'bron_in_profiel'],
            'properties' => [
                'id' => ['type' => 'string'],
                'oordeel' => ['type' => 'string', 'enum' => self::VERDICTS],
                'bewijs' => ['type' => 'string'],
                'bron_in_profiel' => ['type' => ['string', 'null']],
            ],
        ];

        return [
            'type' => 'object',
            'required' => ['eisen', 'wensen', 'senioriteit_fit', 'sterke_punten', 'risicos', 'knock_out_gefaald', 'aanbeveling', 'toelichting'],
            'properties' => [
                'eisen' => ['type' => 'array', 'items' => $item],
                'wensen' => ['type' => 'array', 'items' => $item],
                'senioriteit_fit' => ['type' => 'string', 'enum' => ['te_laag', 'passend', 'te_hoog']],
                'sterke_punten' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3],
                'risicos' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3],
                'knock_out_gefaald' => ['type' => 'array', 'items' => ['type' => 'string']],
                'aanbeveling' => ['type' => 'string', 'enum' => ['aanbieden', 'twijfel', 'niet_aanbieden']],
                'toelichting' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $requirementIds
     * @param  list<string>  $wishIds
     */
    public static function validate(array $data, array $requirementIds, array $wishIds): ?string
    {
        $validator = Validator::make($data, [
            'eisen' => ['present', 'array'],
            'eisen.*.id' => ['required', 'string'],
            'eisen.*.oordeel' => ['required', 'in:'.implode(',', self::VERDICTS)],
            'eisen.*.bewijs' => ['present', 'nullable', 'string'],
            'eisen.*.bron_in_profiel' => ['nullable', 'string'],
            'wensen' => ['present', 'array'],
            'wensen.*.id' => ['required', 'string'],
            'wensen.*.oordeel' => ['required', 'in:'.implode(',', self::VERDICTS)],
            'wensen.*.bewijs' => ['present', 'nullable', 'string'],
            'wensen.*.bron_in_profiel' => ['nullable', 'string'],
            'senioriteit_fit' => ['required', 'in:te_laag,passend,te_hoog'],
            'sterke_punten' => ['present', 'array'],
            'sterke_punten.*' => ['string'],
            'risicos' => ['present', 'array'],
            'risicos.*' => ['string'],
            'knock_out_gefaald' => ['present', 'array'],
            'aanbeveling' => ['required', 'in:aanbieden,twijfel,niet_aanbieden'],
            'toelichting' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return implode(' ', $validator->errors()->all());
        }

        $missing = array_merge(
            array_diff($requirementIds, array_column((array) $data['eisen'], 'id')),
            array_diff($wishIds, array_column((array) $data['wensen'], 'id')),
        );

        return $missing === [] ? null : 'Er ontbreekt een oordeel voor: '.implode(', ', $missing).'.';
    }
}
