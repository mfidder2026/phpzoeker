<?php

namespace App\Filament\Pages;

use App\Domain\Assignments\Models\Assignment;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Offers\Actions\RecordManualOffer;
use App\Domain\Offers\Enums\OfferChannel;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Exceptions\DuplicateOfferException;
use App\Domain\Offers\Support\EmlReader;
use App\Domain\Relations\Models\Organization;
use App\Enums\Role;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\Access;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use UnitEnum;

/**
 * Handmatig registreren (§11.8): aanbiedingen buiten de app (telefoon, Outlook, platform), zodat
 * het register compleet blijft. Een `.eml` vult kopregels en bijlagen automatisch in.
 *
 * @property-read Schema $form
 */
class HandmatigRegistreren extends Page
{
    protected static ?string $slug = 'handmatig-registreren';

    protected static ?string $title = 'Handmatig registreren';

    protected static ?string $navigationLabel = 'Handmatig registreren';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AANBIEDINGEN;

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Access::hasAny([Role::Beheerder, Role::Accountmanager]);
    }

    public function getSubheading(): string
    {
        return 'Voor aanbiedingen die buiten de app zijn gedaan. Upload de verstuurde mail (.eml) om Van, Aan, CC, onderwerp, datum en bijlagen over te nemen.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'channel' => OfferChannel::Manual->value,
            'sent_at' => now()->format('Y-m-d H:i'),
            'sent_by' => Access::user()?->id,
            'status' => OfferStatus::Verzonden->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Verstuurde mail (optioneel)')->schema([
                    FileUpload::make('eml')->label('.eml-bestand')
                        ->disk('local')->directory('offers/uploads')->visibility('private')
                        ->acceptedFileTypes(['message/rfc822', 'application/octet-stream', 'text/plain'])
                        ->maxSize(20480)
                        ->helperText('Outlook: open de mail en kies Bestand → Opslaan als → .eml (Outlook-.msg wordt nog niet ondersteund).')
                        ->live()
                        ->afterStateUpdated(fn (mixed $state, Set $set) => $this->prefillFromEml($state, $set)),
                    FileUpload::make('cv')->label('Verstuurd CV (als het niet in de mail zat)')
                        ->disk('local')->directory('offers/uploads')->visibility('private')->preserveFilenames()
                        ->acceptedFileTypes(['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                        ->maxSize(10240),
                ]),
                Section::make('Aanbieding')->columns(2)->schema([
                    Select::make('candidate_id')->label('Kandidaat')->required()
                        ->options(fn (): array => Candidate::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->live()
                        ->afterStateUpdated(fn (mixed $state, Set $set) => $set('offered_rate', Candidate::query()->find((int) $state)?->hourly_rate)),
                    Select::make('assignment_id')->label('Opdracht')
                        ->options(fn (): array => Assignment::query()->latest()->limit(300)->get()->mapWithKeys(fn (Assignment $assignment): array => [
                            $assignment->id => $assignment->title.($assignment->client_name ? ' — '.$assignment->client_name : '').($assignment->reference ? ' ('.$assignment->reference.')' : ''),
                        ])->all())
                        ->searchable()
                        ->placeholder('Nieuwe opdracht aanmaken')
                        ->live(),
                    TextInput::make('new_title')->label('Titel nieuwe opdracht')->requiredWithout('assignment_id')->hidden(fn (Get $get): bool => filled($get('assignment_id'))),
                    TextInput::make('new_client')->label('Eindklant')->hidden(fn (Get $get): bool => filled($get('assignment_id'))),
                    TextInput::make('new_reference')->label('Referentie')->hidden(fn (Get $get): bool => filled($get('assignment_id'))),
                    Select::make('organization_id')->label('Opdrachtgever/intermediair')
                        ->options(fn (): array => Organization::query()->whereNull('merged_into_id')->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->placeholder('Nieuwe relatie (vul de naam in)')->live(),
                    TextInput::make('organization_name')->label('Naam nieuwe relatie')->hidden(fn (Get $get): bool => filled($get('organization_id'))),
                    TextInput::make('contact_name')->label('Contactpersoon'),
                    TextInput::make('contact_email')->label('E-mail contactpersoon')->email(),
                    Select::make('channel')->label('Kanaal')->required()->options([
                        OfferChannel::Manual->value => 'Handmatig (telefoon, eigen mail)',
                        OfferChannel::Email->value => 'Mail',
                        OfferChannel::Platform->value => 'Platform',
                    ]),
                    DateTimePicker::make('sent_at')->label('Aangeboden op')->seconds(false)->required()->maxDate(now()->addDay()),
                    TextInput::make('offered_rate')->label('Aangeboden tarief (€ per uur, excl. btw)')->numeric()->minValue(0)->required(),
                    Select::make('sent_by')->label('Aangeboden door')->required()->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('response_term_days')->label('Reactietermijn (werkdagen, optioneel)')->numeric()->minValue(1)->maxValue(60)
                        ->helperText('Leeg = standaard van de relatie of de globale standaard.'),
                    Select::make('status')->label('Status')->required()->options(collect([OfferStatus::Verzonden, OfferStatus::ReactieOntvangen, OfferStatus::IntakeGepland, OfferStatus::Afgewezen, OfferStatus::Geplaatst])
                        ->mapWithKeys(fn (OfferStatus $status): array => [$status->value => $status->getLabel()])->all()),
                    Textarea::make('note')->label('Notitie')->rows(3)->columnSpanFull(),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Registreren')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $user = Access::user() ?? abort(403);

        try {
            $offer = app(RecordManualOffer::class)->handle([
                ...$state,
                'eml_path' => $this->firstFile($state['eml'] ?? null),
                'cv_path' => $this->firstFile($state['cv'] ?? null),
            ], $user);
        } catch (DuplicateOfferException $exception) {
            $notification = Notification::make()->danger()->title($exception->getMessage());

            if ($exception->existing !== null) {
                $notification->actions([Action::make('bekijken')->label('Bestaande aanbieding')->url(OfferResource::getUrl('view', ['record' => $exception->existing]))]);
            }

            $notification->send();

            return;
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Aanbieding geregistreerd')->send();
        $this->redirect(OfferResource::getUrl('view', ['record' => $offer]));
    }

    private function prefillFromEml(mixed $state, Set $set): void
    {
        $file = is_array($state) ? reset($state) : $state;

        if (! is_object($file) || ! method_exists($file, 'getRealPath')) {
            return;
        }

        try {
            $mail = EmlReader::read((string) $file->getRealPath());
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        if ($mail['date'] !== null) {
            $set('sent_at', $mail['date']->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'));
        }

        if (($mail['to'][0] ?? null) !== null) {
            $set('contact_email', $mail['to'][0]);
        }

        $set('channel', OfferChannel::Email->value);
        $set('new_title', $mail['subject']);

        Notification::make()->info()->title('Gegevens uit de mail overgenomen')
            ->body('Van '.($mail['from'] ?? '?').' aan '.implode(', ', $mail['to']).(filled($mail['cc']) ? ' · CC '.implode(', ', $mail['cc']) : '').' · '.count($mail['attachments']).' bijlage(n)')
            ->send();
    }

    private function firstFile(mixed $value): ?string
    {
        $path = is_array($value) ? (reset($value) ?: null) : $value;

        return is_string($path) && Storage::disk('local')->exists($path) ? $path : null;
    }
}
