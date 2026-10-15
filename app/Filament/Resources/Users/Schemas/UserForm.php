<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Gebruiker')->columns(2)->schema([
                TextInput::make('name')->label('Naam')->required()->maxLength(255),
                TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')->label('Wachtwoord')
                    ->password()->revealable()
                    ->rule(Password::min(12))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Laat leeg om het huidige wachtwoord te behouden.'),
            ]),
            Section::make('Rechten')->columns(2)->schema([
                Select::make('roles')->label('Rollen')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => Role::tryFrom($record->name)?->getLabel() ?? $record->name)
                    ->multiple()->preload()->required()->live(),
                Select::make('candidate_id')->label('Gekoppelde kandidaat')
                    ->relationship('candidate', 'name')
                    ->helperText('Verplicht bij de rol Kandidaat: bepaalt welke eigen gegevens zichtbaar zijn.')
                    ->required(fn (Get $get): bool => self::hasKandidaatRole((array) $get('roles'))),
            ]),
        ]);
    }

    /**
     * @param  array<int|string>  $roleIds
     */
    private static function hasKandidaatRole(array $roleIds): bool
    {
        return \Spatie\Permission\Models\Role::query()->whereKey($roleIds)->where('name', Role::Kandidaat->value)->exists();
    }
}
