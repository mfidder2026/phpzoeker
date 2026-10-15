<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Naam')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable(),
                TextColumn::make('roles.name')->label('Rollen')->badge()
                    ->formatStateUsing(fn (string $state): string => Role::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('candidate.name')->label('Kandidaat')->placeholder('—'),
                IconColumn::make('app_authentication_secret')->label('2FA')->boolean()
                    ->state(fn ($record): bool => filled($record->app_authentication_secret)),
                TextColumn::make('created_at')->label('Aangemaakt')->date('d-m-Y')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
