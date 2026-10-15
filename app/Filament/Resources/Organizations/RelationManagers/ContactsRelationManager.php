<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Contactpersonen van een relatie (§11.9).
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contactpersonen';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Naam')->maxLength(200),
            TextInput::make('role')->label('Functie')->maxLength(200),
            TextInput::make('email')->label('E-mail')->email()->maxLength(250),
            TextInput::make('phone')->label('Telefoon')->tel()->maxLength(50),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Naam')->placeholder('—')->searchable(),
                TextColumn::make('role')->label('Functie')->placeholder('—'),
                TextColumn::make('email')->label('E-mail')->placeholder('—')->copyable(),
                TextColumn::make('last_used_at')->label('Laatst gebruikt')->dateTime('d-m-Y')->placeholder('—')->sortable(),
            ])
            ->headerActions([CreateAction::make()->label('Contactpersoon toevoegen')])
            ->recordActions([EditAction::make()]);
    }
}
