<?php

namespace App\Ai\Runs;

use Filament\Support\Contracts\HasLabel;

/**
 * Taken waarvoor per agentprofiel een eigen model is in te stellen (§18.5).
 */
enum AiTask: string implements HasLabel
{
    case Extract = 'extract';
    case Match = 'match';
    case Research = 'research';
    case Cv = 'cv';
    case Grounding = 'grounding';
    case Mail = 'mail';
    case ConnectionTest = 'connection_test';

    public function getLabel(): string
    {
        return match ($this) {
            self::Extract => 'Extractie',
            self::Match => 'Matching',
            self::Research => 'Website-onderzoek',
            self::Cv => 'CV herschrijven',
            self::Grounding => 'Grounding-check',
            self::Mail => 'Mailteksten',
            self::ConnectionTest => 'Verbindingstest',
        };
    }

    /**
     * @return list<self>
     */
    public static function modelTasks(): array
    {
        return [self::Extract, self::Match, self::Research, self::Cv, self::Grounding, self::Mail];
    }

    public function defaultMaxOutputTokens(): int
    {
        return match ($this) {
            self::Extract => 4000,
            self::Match => 4000,
            self::Research => 3000,
            self::Cv => 8000,
            self::Grounding => 3000,
            self::Mail => 1500,
            self::ConnectionTest => 20,
        };
    }
}
