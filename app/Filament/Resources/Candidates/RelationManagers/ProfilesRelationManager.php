<?php

namespace App\Filament\Resources\Candidates\RelationManagers;

use App\Ai\Agents\CvImporter;
use App\Domain\Candidates\Actions\ApproveMasterProfile;
use App\Domain\Candidates\Data\CandidateProfileSchema;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Candidates\Models\CandidateProfile;
use App\Domain\Documents\Contracts\PdfConverter;
use App\Domain\Documents\CvTemplateRenderer;
use App\Filament\Resources\Candidates\Schemas\MasterProfileEditor;
use App\Filament\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Master-profiel met versiehistorie: import → review → goedkeuren (FR-K4, §12.1).
 * Een goedgekeurde versie is bevroren; wijzigen = nieuwe versie maken.
 *
 * @property Candidate $ownerRecord
 */
class ProfilesRelationManager extends RelationManager
{
    protected static string $relationship = 'profiles';

    protected static ?string $title = 'Master-profiel';

    protected static ?string $modelLabel = 'profielversie';

    protected static ?string $pluralModelLabel = 'profielversies';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::isStaff() || Access::user()?->hasRole('reviewer') === true;
    }

    public function isReadOnly(): bool
    {
        return ! Access::isStaff();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(MasterProfileEditor::components());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (CandidateProfile $record): string => 'versie '.$record->version)
            ->defaultSort('version', 'desc')
            ->emptyStateHeading('Nog geen master-profiel')
            ->emptyStateDescription('Importeer het Word-CV via "CV importeren" rechtsboven, of maak een leeg profiel.')
            ->columns([
                TextColumn::make('version')->label('Versie')->prefix('v')->sortable(),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (CandidateProfile $record): string => match (true) {
                        $record->id === $this->ownerRecord->active_profile_id => 'Actief',
                        $record->isApproved() => 'Goedgekeurd',
                        default => 'Concept',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Actief' => 'success',
                        'Goedgekeurd' => 'info',
                        default => 'warning',
                    }),
                TextColumn::make('summary')->label('Inhoud')->state(fn (CandidateProfile $record): string => sprintf(
                    '%d werkervaring · %d projecten · %d opleidingen · %d skills',
                    count((array) ($record->data['werkervaring'] ?? [])),
                    count((array) ($record->data['projecten'] ?? [])),
                    count((array) ($record->data['opleidingen'] ?? [])),
                    count($record->skills_vocabulary ?? []),
                )),
                TextColumn::make('approver.name')->label('Goedgekeurd door')->placeholder('—'),
                TextColumn::make('approved_at')->label('Goedgekeurd op')->dateTime('d-m-Y H:i')->placeholder('—'),
                TextColumn::make('model')->label('Model')->placeholder('handmatig')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('prompt_version')->label('Prompt')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Aangemaakt')->dateTime('d-m-Y H:i'),
            ])
            ->headerActions([
                Action::make('createEmpty')
                    ->label('Leeg profiel maken')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('gray')
                    ->visible(fn (): bool => Access::isStaff())
                    ->action(function (): void {
                        $this->ownerRecord->profiles()->create([
                            'version' => $this->ownerRecord->nextProfileVersion(),
                            'data' => CandidateProfileSchema::empty($this->ownerRecord->name),
                            'skills_vocabulary' => [],
                        ]);
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Bekijken')
                    ->modalWidth('5xl')
                    ->schema(fn (): array => [])
                    ->modalContent(fn (CandidateProfile $record) => view('filament.candidates.profile-preview', ['data' => $record->data, 'record' => $record])),
                EditAction::make()->label('Bewerken')
                    ->modalWidth('7xl')
                    ->visible(fn (CandidateProfile $record): bool => Access::isStaff() && ! $record->isApproved())
                    ->mutateDataUsing(function (array $data): array {
                        $data['data'] = CvImporter::normalize((array) ($data['data'] ?? []));
                        $data['skills_vocabulary'] = CandidateProfileSchema::skillsVocabulary($data['data']);

                        return $data;
                    }),
                Action::make('approve')->label('Goedkeuren')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (CandidateProfile $record): bool => Access::isStaff() && ! $record->isApproved())
                    ->requiresConfirmation()
                    ->modalHeading('Master-profiel goedkeuren')
                    ->modalDescription('Na goedkeuring is deze versie de enige bron van waarheid voor getailorde CV\'s en wordt hij bevroren. Controleer of alle feiten kloppen.')
                    ->action(function (CandidateProfile $record): void {
                        $errors = app(ApproveMasterProfile::class)->handle($record, Access::user());

                        if ($errors !== []) {
                            Notification::make()->danger()->title('Profiel is nog niet geldig')
                                ->body(implode("\n", array_slice($errors, 0, 6)))->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title('Versie '.$record->version.' is goedgekeurd en actief')->send();
                    }),
                ActionGroup::make([
                    Action::make('downloadDocx')->label('Word')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->color('gray')
                        ->tooltip('Master-CV in de Fremen-template (Word)')
                        ->action(fn (CandidateProfile $record) => response()->download($this->renderDocx($record))->deleteFileAfterSend()),
                    Action::make('downloadPdf')->label('PDF')
                        ->icon(Heroicon::OutlinedDocumentArrowDown)
                        ->color('gray')
                        ->tooltip('Master-CV als PDF (via Gotenberg)')
                        ->action(function (CandidateProfile $record) {
                            $docx = $this->renderDocx($record);

                            try {
                                $pdf = app(PdfConverter::class)->convert($docx, preg_replace('/\.docx$/', '.pdf', $docx) ?? $docx.'.pdf');
                            } catch (\RuntimeException $exception) {
                                Notification::make()->danger()->title('PDF maken lukt niet')->body($exception->getMessage())->send();

                                return null;
                            } finally {
                                @unlink($docx);
                            }

                            return response()->download($pdf)->deleteFileAfterSend();
                        }),
                    Action::make('newVersion')->label('Nieuwe versie')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->color('gray')
                        ->visible(fn (CandidateProfile $record): bool => Access::isStaff() && $record->isApproved())
                        ->action(function (CandidateProfile $record): void {
                            $this->ownerRecord->profiles()->create([
                                'version' => $this->ownerRecord->nextProfileVersion(),
                                'data' => $record->data,
                                'skills_vocabulary' => $record->skills_vocabulary,
                                'source_file_path' => $record->source_file_path,
                            ]);

                            Notification::make()->success()->title('Conceptversie gemaakt op basis van v'.$record->version)->send();
                        }),
                ])->tooltip('Meer'),
            ]);
    }

    /**
     * Rendert het master-CV naar een tijdelijk bestand: "CV {Naam} - Fremen - master v{n}.docx" (§12.7).
     */
    private function renderDocx(CandidateProfile $record): string
    {
        $candidate = $this->ownerRecord;
        $path = storage_path('app/private/tmp/'.uniqid('cv-', true).'/CV '.$candidate->name.' - Fremen - master v'.$record->version.'.docx');

        return app(CvTemplateRenderer::class)->render($candidate, $record->data, $path);
    }
}
