<?php

namespace App\Console\Commands;

use App\Domain\Offers\Actions\ImportOffers;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Eerdere aanbiedingen importeren (FR-AR12). Kolommen: zie `docs/voorbeeld-aanbiedingen.csv`.
 */
#[Signature('offers:import {file : CSV-bestand} {--user= : E-mailadres van de gebruiker die importeert}')]
#[Description('Importeert eerdere aanbiedingen in het aanbiedingsregister')]
class ImportOffersCommand extends Command
{
    public function handle(ImportOffers $import): int
    {
        $option = $this->option('user');
        $email = is_string($option) ? $option : '';
        $user = $email !== '' ? User::query()->where('email', $email)->first() : User::query()->oldest('id')->first();

        if ($user === null) {
            $this->components->error('Gebruiker niet gevonden.');

            return self::FAILURE;
        }

        try {
            $file = $this->argument('file');
            $result = $import->handle(is_string($file) ? $file : '', $user);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['skipped'] as $message) {
            $this->components->warn($message);
        }

        $this->components->info("{$result['imported']} aanbiedingen geïmporteerd, ".count($result['skipped']).' overgeslagen.');

        return self::SUCCESS;
    }
}
