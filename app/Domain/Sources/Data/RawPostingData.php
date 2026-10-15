<?php

namespace App\Domain\Sources\Data;

use DateTimeImmutable;

final readonly class RawPostingData
{
    /**
     * @param  string  $externalId  stabiel per bron (URL, referentie of Message-ID)
     * @param  string  $bodyText  platte tekst, gestript van HTML
     * @param  array<string, mixed>  $meta  bronspecifiek: afzender, labels, soort (bijv. 'alert_mail')
     */
    public function __construct(
        public string $externalId,
        public ?string $url,
        public string $title,
        public string $bodyText,
        public ?string $bodyHtml,
        public DateTimeImmutable $publishedAt,
        public array $meta = [],
    ) {}
}
