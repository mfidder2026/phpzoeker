<?php

namespace App\Domain\Notifications;

use App\Settings\NotificationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Meldingen naar een Microsoft Teams-kanaal via een Workflows-webhook (FR-N4). Alleen als de
 * webhook-URL is ingesteld; een mislukte melding stopt de rest nooit.
 */
class TeamsNotifier
{
    public function __construct(private readonly NotificationSettings $settings) {}

    public function enabled(): bool
    {
        return filled($this->settings->teams_webhook_url);
    }

    public function send(string $title, string $text, ?string $url = null, string $urlLabel = 'Openen'): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $card = [
            'type' => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body' => [
                ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                ['type' => 'TextBlock', 'text' => $text, 'wrap' => true],
            ],
            'actions' => $url === null ? [] : [['type' => 'Action.OpenUrl', 'title' => $urlLabel, 'url' => $url]],
        ];

        try {
            return Http::timeout(10)->post((string) $this->settings->teams_webhook_url, [
                'type' => 'message',
                'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]],
            ])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }
}
