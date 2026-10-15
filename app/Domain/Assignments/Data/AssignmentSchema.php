<?php

namespace App\Domain\Assignments\Data;

use Illuminate\Support\Facades\Validator;

/**
 * Schema van de opdrachtextractie (Bijlage B): JSON Schema voor het model en Laravel-validatie
 * van de output (§18.1).
 */
final class AssignmentSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $s = ['type' => ['string', 'null']];
        $n = ['type' => ['number', 'null']];
        $b = ['type' => ['boolean', 'null']];

        return [
            'type' => 'object',
            'required' => ['is_relevant_softwareontwikkeling', 'titel', 'taal_opdracht', 'werkvorm', 'contractvorm', 'sector', 'senioriteit', 'eisen', 'wensen', 'technologieen', 'samenvatting'],
            'properties' => [
                'is_relevant_softwareontwikkeling' => ['type' => 'boolean'],
                'titel' => ['type' => 'string'],
                'eindklant' => $s,
                'intermediair' => $s,
                'referentie' => $s,
                'contact' => ['type' => 'object', 'properties' => ['naam' => $s, 'email' => $s, 'telefoon' => $s]],
                'taal_opdracht' => ['type' => 'string', 'enum' => ['nl', 'en']],
                'locatie' => ['type' => 'object', 'properties' => ['tekst' => $s, 'plaats' => $s, 'provincie' => $s, 'land' => $s]],
                'werkvorm' => ['type' => 'string', 'enum' => ['onsite', 'hybride', 'remote', 'onbekend']],
                'dagen_op_locatie_per_week' => $n,
                'uren_per_week' => ['type' => 'object', 'properties' => ['min' => $n, 'max' => $n]],
                'start' => ['type' => 'object', 'properties' => ['datum' => $s, 'zo_snel_mogelijk' => ['type' => 'boolean']]],
                'einde' => ['type' => 'object', 'properties' => ['datum' => $s, 'duur_maanden' => $n, 'verlenging' => $s]],
                'tarief' => ['type' => 'object', 'properties' => ['max_per_uur' => $n, 'incl_marge' => $b, 'originele_tekst' => $s]],
                'contractvorm' => ['type' => 'string', 'enum' => ['detachering', 'zzp', 'beide', 'loondienst', 'onbekend']],
                'zzp_toegestaan' => $b,
                'sector' => ['type' => 'string', 'enum' => ['overheid', 'semi_overheid', 'zakelijk', 'onbekend']],
                'senioriteit' => ['type' => 'string', 'enum' => ['junior', 'medior', 'senior', 'lead', 'onbekend']],
                'deadline' => $s,
                'eisen' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['id', 'tekst', 'knock_out', 'categorie'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'tekst' => ['type' => 'string'],
                        'knock_out' => ['type' => 'boolean'],
                        'categorie' => ['type' => 'string', 'enum' => ['techniek', 'ervaring', 'opleiding', 'certificaat', 'screening', 'taal', 'overig']],
                        'min_jaren' => $n,
                        'technologie' => $s,
                    ],
                ]],
                'wensen' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['id', 'tekst', 'categorie'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'tekst' => ['type' => 'string'],
                        'gewicht' => $n,
                        'categorie' => ['type' => 'string', 'enum' => ['techniek', 'ervaring', 'opleiding', 'certificaat', 'competentie', 'overig']],
                    ],
                ]],
                'technologieen' => ['type' => 'array', 'items' => ['type' => 'string']],
                'screening' => ['type' => 'object', 'properties' => ['vog' => $b, 'nationaliteit' => $s, 'veiligheidsonderzoek' => $s]],
                'indieningsinstructies' => ['type' => 'object', 'properties' => ['alleen_via_platform' => $b, 'max_tekens_per_eis' => $n, 'overig' => $s]],
                'samenvatting' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function validate(array $data): ?string
    {
        $validator = Validator::make($data, [
            'is_relevant_softwareontwikkeling' => ['required', 'boolean'],
            'titel' => ['required', 'string'],
            'taal_opdracht' => ['nullable', 'in:nl,en'],
            'werkvorm' => ['nullable', 'in:onsite,hybride,remote,onbekend'],
            'contractvorm' => ['nullable', 'in:detachering,zzp,beide,loondienst,onbekend'],
            'sector' => ['nullable', 'in:overheid,semi_overheid,zakelijk,onbekend'],
            'senioriteit' => ['nullable', 'in:junior,medior,senior,lead,onbekend'],
            'start.datum' => ['nullable', 'date_format:Y-m-d'],
            'einde.datum' => ['nullable', 'date_format:Y-m-d'],
            'deadline' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?$/'],
            'uren_per_week.min' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'uren_per_week.max' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'tarief.max_per_uur' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'contact.email' => ['nullable', 'email'],
            'eisen' => ['present', 'array'],
            'eisen.*.id' => ['required', 'string'],
            'eisen.*.tekst' => ['required', 'string'],
            'eisen.*.knock_out' => ['required', 'boolean'],
            'wensen' => ['present', 'array'],
            'wensen.*.id' => ['required', 'string'],
            'wensen.*.tekst' => ['required', 'string'],
            'technologieen' => ['present', 'array'],
        ]);

        return $validator->fails() ? implode(' ', array_slice($validator->errors()->all(), 0, 6)) : null;
    }
}
