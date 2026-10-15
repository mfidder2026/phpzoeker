<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\NavigationGroup;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Support\Access;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Audit-log: wie deed wat en wanneer (§15.5). Alleen-lezen.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'audit-log';

    protected static ?string $modelLabel = 'logregel';

    protected static ?string $pluralModelLabel = 'Audit-log';

    protected static ?string $navigationLabel = 'Audit-log';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::BEHEER;

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function canAccess(): bool
    {
        return Access::isBeheerder();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Tijdstip')->dateTime('d-m-Y H:i:s')->sortable(),
                TextColumn::make('causer.name')->label('Gebruiker')->placeholder('Systeem'),
                TextColumn::make('log_name')->label('Onderdeel')->badge()->color('gray'),
                TextColumn::make('event')->label('Actie')->placeholder('—'),
                TextColumn::make('description')->label('Omschrijving')->wrap()->searchable(),
                TextColumn::make('subject_type')->label('Object')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state === null ? '—' : class_basename($state).' #'.$record->subject_id),
            ])
            ->filters([
                SelectFilter::make('causer_id')->label('Gebruiker')->options(fn (): array => User::query()->pluck('name', 'id')->all()),
                SelectFilter::make('log_name')->label('Onderdeel')->options(fn (): array => Activity::query()->distinct()->whereNotNull('log_name')->pluck('log_name', 'log_name')->all()),
                SelectFilter::make('event')->label('Actie')->options(['created' => 'Aangemaakt', 'updated' => 'Gewijzigd', 'deleted' => 'Verwijderd']),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Tijdstip')->dateTime('d-m-Y H:i:s'),
            TextEntry::make('causer.name')->label('Gebruiker')->placeholder('Systeem'),
            TextEntry::make('description')->label('Omschrijving')->columnSpanFull(),
            KeyValueEntry::make('attribute_changes.attributes')->label('Nieuwe waarden')->columnSpanFull(),
            KeyValueEntry::make('attribute_changes.old')->label('Oude waarden')->columnSpanFull(),
            KeyValueEntry::make('properties')->label('Eigenschappen')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
