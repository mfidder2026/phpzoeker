<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Settings\MailSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MailSettingsPage extends BaseSettingsPage
{
    protected static string $settings = MailSettings::class;

    protected static ?string $slug = 'mail';

    protected static ?string $navigationLabel = 'Mail';

    protected static ?string $title = 'Mail';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Afzender')->columns(2)->schema([
                Select::make('provider')->label('Mailprovider')->required()->options([
                    'graph' => 'Microsoft 365 (Graph)',
                    'gmail' => 'Google Workspace (Gmail)',
                    'smtp' => 'SMTP',
                ])->helperText('SMTP-gegevens staan in .env (secrets).'),
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
