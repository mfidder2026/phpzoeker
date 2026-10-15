<?php

namespace App\Filament\Resources\Organizations;

use App\Domain\Relations\Enums\OrganizationType;
use App\Domain\Relations\Models\Organization;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Organizations\RelationManagers\OffersRelationManager;
use App\Filament\Support\Access;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Relaties (§11.9): opdrachtgevers, intermediairs en platforms met contactpersonen, standaard en
 * gemeten reactietijd, en alle aanbiedingen aan die partij.
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $slug = 'relaties';

    protected static ?string $modelLabel = 'relatie';

    protected static ?string $pluralModelLabel = 'relaties';

    protected static ?string $navigationLabel = 'Relaties';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::MENSEN;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return Access::isStaff();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('merged_into_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organisatie')->columns(2)->schema([
                TextInput::make('name')->label('Naam')->required()->maxLength(200),
                Select::make('type')->label('Type')->options(OrganizationType::class)->required(),
                TextInput::make('website')->label('Website')->url()->maxLength(250),
                TagsInput::make('email_domains')->label('E-maildomeinen')->placeholder('bijv. headfirst.nl'),
                TextInput::make('supplier_status')->label('Leveranciersstatus Fremen')->placeholder('bijv. geregistreerd leverancier sinds 2025'),
                Select::make('preferred_channel')->label('Voorkeurskanaal')->options(['email' => 'Mail', 'platform' => 'Platform']),
                Textarea::make('notes')->label('Notities')->rows(3)->columnSpanFull(),
            ]),
            Section::make('Reactietijd (§11.6)')->columns(2)->schema([
                TextInput::make('default_response_term_days')->label('Standaard reactietermijn (werkdagen)')->numeric()->minValue(1)->maxValue(60)
                    ->helperText('Leeg = globale standaard uit Instellingen → Register.'),
                Text::make(fn (?Organization $record): string => $record?->measured_avg_response_days !== null
                    ? 'Gemeten: gemiddeld '.str_replace('.', ',', (string) $record->measured_avg_response_days).' werkdagen over '.$record->measured_sample_size.' aanbiedingen (laatste 12 maanden).'
                    : 'Nog geen gemeten reactietijd (vanaf 3 reacties).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['offers', 'contacts'])->withMax('offers', 'sent_at'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Naam')->searchable()->sortable()->weight('semibold')
                    ->description(fn (Organization $record): ?string => $record->website),
                TextColumn::make('type')->label('Type')->badge(),
                TextColumn::make('offers_count')->label('Aanbiedingen')->alignCenter()->sortable(),
                TextColumn::make('contacts_count')->label('Contactpersonen')->alignCenter(),
                TextColumn::make('default_response_term_days')->label('Standaard termijn')->suffix(' wd')->placeholder('globaal'),
                TextColumn::make('measured_avg_response_days')->label('Gemeten')->suffix(' wd')->placeholder('—'),
                TextColumn::make('offers_max_sent_at')->label('Laatste aanbieding')->dateTime('d-m-Y')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Type')->options(OrganizationType::class),
            ])
            ->recordActions([EditAction::make()->label('Openen')]);
    }

    public static function getRelations(): array
    {
        return [ContactsRelationManager::class, OffersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'create' => CreateOrganization::route('/create'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }
}
