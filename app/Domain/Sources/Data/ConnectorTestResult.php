<?php

namespace App\Domain\Sources\Data;

final readonly class ConnectorTestResult
{
    /**
     * @param  list<array{title: string, url: string|null, published_at: string}>  $samples
     */
    public function __construct(
        public bool $passed,
        public string $message,
        public int $count = 0,
        public array $samples = [],
    ) {}

    /**
     * @param  iterable<RawPostingData>  $postings
     */
    public static function fromPostings(iterable $postings, int $limit = 5): self
    {
        $samples = [];
        $count = 0;

        foreach ($postings as $posting) {
            $count++;

            if (count($samples) < $limit) {
                $samples[] = ['title' => $posting->title, 'url' => $posting->url, 'published_at' => $posting->publishedAt->format('d-m-Y H:i')];
            }
        }

        return new self(true, "{$count} publicaties gevonden.", $count, $samples);
    }
}
