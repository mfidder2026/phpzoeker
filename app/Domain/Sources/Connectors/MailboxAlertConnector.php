<?php

namespace App\Domain\Sources\Connectors;

use App\Ai\Tools\FetchPageTool;
use App\Domain\Sources\Contracts\AcknowledgesPostings;
use App\Domain\Sources\Contracts\SourceConnector;
use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Data\RawPostingData;
use App\Domain\Sources\Mailbox\MailboxClientFactory;
use App\Domain\Sources\Models\Source;
use App\Settings\IngestSettings;
use DateTimeImmutable;
use Throwable;

/**
 * Alerts-mailbox (§8.1 route 2, kern van v1), via Microsoft Graph (Microsoft 365) of IMAP. Elke mail wordt één ruwe publicatie; bevat de
 * mail meerdere opdrachten, dan splitst de verwerking hem op (AlertMailSplitter). Een mail
 * van het eigen domein is een doorgestuurde aanvraag (§8.6).
 */
class MailboxAlertConnector implements AcknowledgesPostings, SourceConnector
{
    public function __construct(
        private readonly MailboxClientFactory $mailboxes,
        private readonly IngestSettings $settings,
    ) {}

    public function key(): string
    {
        return 'mailbox_alert';
    }

    public function fetch(Source $source, ?DateTimeImmutable $since): iterable
    {
        $internalDomains = array_map('strtolower', (array) $source->configValue('internal_domains', ['fremen.app']));

        foreach ($this->mailboxes->for($source->config ?? [])->unprocessed(self::config($source), $since, $this->settings->max_items_per_run) as $mail) {
            $text = trim($mail->text) !== '' ? $mail->text : FetchPageTool::extractText((string) $mail->html)['text'];

            yield new RawPostingData(
                externalId: $mail->messageId,
                url: null,
                title: $mail->subject !== '' ? $mail->subject : '(geen onderwerp)',
                bodyText: $text,
                bodyHtml: $mail->html,
                publishedAt: $mail->date,
                meta: [
                    'kind' => in_array($mail->senderDomain(), $internalDomains, true) ? 'forwarded_mail' : 'alert_mail',
                    'from' => $mail->from,
                    'sender_domain' => $mail->senderDomain(),
                    'uid' => $mail->uid,
                ],
            );
        }
    }

    public function acknowledge(Source $source, RawPostingData $posting): void
    {
        if (isset($posting->meta['uid'])) {
            $this->mailboxes->for($source->config ?? [])->markProcessed(self::config($source), (string) $posting->meta['uid']);
        }
    }

    /**
     * Bronconfiguratie plus de bron-ID, zodat vernieuwde Microsoft-tokens bewaard kunnen worden.
     *
     * @return array<string, mixed>
     */
    private static function config(Source $source): array
    {
        return [...($source->config ?? []), 'source_id' => $source->id];
    }

    public function test(Source $source): ConnectorTestResult
    {
        try {
            return ConnectorTestResult::fromPostings($this->fetch($source, null));
        } catch (Throwable $exception) {
            return new ConnectorTestResult(false, $exception->getMessage());
        }
    }
}
