<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Enums\OutboundEmailType;
use App\Domain\Offers\Mailers\OutboundMailer;
use App\Domain\Offers\Mailers\SenderConnection;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Sources\Mailbox\MicrosoftLogin;
use App\Filament\Support\Access;
use App\Settings\MailSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use RuntimeException;

class MailSettingsPage extends BaseSettingsPage
{
    protected static string $settings = MailSettings::class;

    protected static ?string $slug = 'mail';

    protected static ?string $navigationLabel = 'Mail';

    protected static ?string $title = 'Mail';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    /** Velden van de Microsoft-verbinding: worden nooit in het formulier gezet (zoals R9). */
    private const CONNECTION_FIELDS = ['graph_refresh_token', 'graph_tenant', 'graph_account', 'graph_connected_at'];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return Arr::except($data, self::CONNECTION_FIELDS);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return parent::mutateFormDataBeforeSave(Arr::except($data, self::CONNECTION_FIELDS));
    }

    #[On('microsoft-connected')]
    public function refreshConnection(): void
    {
        app()->forgetInstance(MailSettings::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('microsoftLogin')
                ->label(fn (): string => $this->connection()->refreshToken() !== null ? 'Opnieuw inloggen bij Microsoft' : 'Inloggen bij Microsoft')
                ->icon(Heroicon::OutlinedKey)
                ->color(fn (): string => $this->connection()->refreshToken() !== null ? 'gray' : 'primary')
                ->modalHeading('Verzenden via Microsoft 365')
                ->modalDescription('Log één keer in met het account van de afzendermailbox (of een account met "Verzenden als" op die mailbox).')
                ->modalWidth('lg')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Sluiten')
                ->modalContent(fn () => view('filament.settings.microsoft-sender-login')),
            Action::make('testMail')
                ->label('Testmail sturen')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Stuurt een testmail vanuit '.app(MailSettings::class)->sender_mailbox.' naar jouw eigen adres ('.(Access::user()->email ?? '').'), via de ingestelde mailprovider.')
                ->action(fn () => $this->sendTestMail()),
            Action::make('microsoftDisconnect')
                ->label('Verbinding verbreken')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->visible(fn (): bool => $this->connection()->refreshToken() !== null)
                ->requiresConfirmation()
                ->modalDescription('PHP Zoeker vergeet de Microsoft-verbinding voor verzenden. Daarna is opnieuw inloggen nodig om via Microsoft 365 te versturen.')
                ->action(function (): void {
                    app(MicrosoftLogin::class)->disconnect($this->connection());
                    Notification::make()->success()->title('Verbinding verbroken')->send();
                }),
        ];
    }

    private function connection(): SenderConnection
    {
        return new SenderConnection(app(MailSettings::class));
    }

    private function sendTestMail(): void
    {
        $user = Access::user() ?? abort(403);
        $settings = app(MailSettings::class);
        $text = "Dit is een testmail van PHP Zoeker.

Verstuurd vanuit {$settings->sender_mailbox} via ".($settings->provider === 'graph' ? 'Microsoft 365' : 'SMTP').' op '.now()->format('d-m-Y H:i').'.';

        $email = new OutboundEmail([
            'type' => OutboundEmailType::Consent,
            'from' => $settings->sender_mailbox,
            'from_name' => $settings->sender_name,
            'to' => [(string) $user->email],
            'cc' => [],
            'bcc' => [],
            'subject' => '[PHP Zoeker] Testmail',
            'body_text' => $text.'
',
            'body_html' => OfferWorkflow::textToHtml($text),
            'attachments' => [],
        ]);

        try {
            app(OutboundMailer::class)->send($email);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('Testmail mislukt')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Testmail verstuurd naar '.$user->email)->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Afzender')->columns(2)->schema([
                Text::make(fn (): string => ($account = $this->connection()->account()) !== null && $this->connection()->refreshToken() !== null
                    ? 'Microsoft 365: verbonden als '.$account.($this->connection()->connectedAt() ? ' sinds '.Carbon::parse($this->connection()->connectedAt())->format('d-m-Y H:i') : '').'.'
                    : 'Microsoft 365: nog niet verbonden. Kies Microsoft 365 hieronder en klik op "Inloggen bij Microsoft" rechtsboven.')->columnSpanFull(),
                Select::make('provider')->label('Mailprovider')->required()->options([
                    'graph' => 'Microsoft 365 (Graph)',
                    'gmail' => 'Google Workspace (Gmail)',
                    'smtp' => 'SMTP',
                ])->helperText('Microsoft 365: eenmalig inloggen (rechtsboven); de mail staat daarna ook in Verzonden items. SMTP-gegevens staan in .env.'),
                TextInput::make('sender_mailbox')->label('Afzendermailbox')->email()->required(),
                TextInput::make('sender_name')->label('Afzendernaam')->required(),
                TextInput::make('alerts_mailbox')->label('Alerts-mailbox')->email()->required(),
            ]),
            Section::make('Aanbiedingen')->columns(2)->schema([
                TagsInput::make('default_offer_cc')->label('Standaard-CC bij aanbiedingen')
                    ->nestedRecursiveRules(['email'])
                    ->helperText('Per aanbieding aan te passen. CC wordt nooit door het taalmodel bepaald (R5).'),
                TextInput::make('bcc_archive')->label('BCC-archief')->email()->nullable(),
                Toggle::make('skip_cc_to_sender')->label('CC naar afzender overslaan'),
                Toggle::make('require_candidate_consent')->label('Akkoord kandidaat vereist vóór versturen'),
            ]),
            Section::make('Handtekening')->schema([
                Textarea::make('signature_html')->label('Handtekening (HTML)')->rows(5),
                Textarea::make('signature_text')->label('Handtekening (platte tekst)')->rows(5),
            ]),
        ]);
    }
}
