<?php

namespace App\Domain\Sources\Connectors;

use App\Ai\Tools\FetchPageTool;
use App\Domain\Sources\Contracts\SourceConnector;
use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Data\RawPostingData;
use App\Domain\Sources\Models\Source;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Officiële RSS- of Atom-feed van een bron (§8.1, route 1). Respecteert robots.txt en gebruikt
 * de herkenbare user agent uit §8.4. Bevat een item alleen een titel, dan haalt de verwerking
 * later de volledige openbare pagina op.
 */
class FeedConnector implements SourceConnector
{
    public function __construct(private readonly FetchPageTool $pages) {}

    public function key(): string
    {
        return 'feed';
    }

    public function fetch(Source $source, ?DateTimeImmutable $since): iterable
    {
        $url = (string) ($source->configValue('feed_url') ?? $source->base_url);

        if ($url === '') {
            throw new RuntimeException('Er is geen feed-URL ingesteld.');
        }

        if (! $this->pages->allowedByRobots($url)) {
            throw new RuntimeException('robots.txt staat het ophalen van deze feed niet toe.');
        }

        try {
            $response = Http::withUserAgent((string) config('phpzoeker.user_agent'))->timeout(30)->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException("De feed {$url} is niet bereikbaar.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("De feed gaf HTTP {$response->status()} terug.");
        }

        return $this->parse($response->body());
    }

    public function test(Source $source): ConnectorTestResult
    {
        try {
            return ConnectorTestResult::fromPostings($this->fetch($source, null));
        } catch (Throwable $exception) {
            return new ConnectorTestResult(false, $exception->getMessage());
        }
    }

    /**
     * @return list<RawPostingData>
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);

        if ($feed === false) {
            throw new RuntimeException('De feed is geen geldige RSS of Atom.');
        }

        $postings = [];

        if (isset($feed->channel)) {
            foreach ($feed->channel->item as $item) {
                $content = $item->children('http://purl.org/rss/1.0/modules/content/');
                $html = trim((string) ($content->encoded ?? '')) ?: trim((string) $item->description);
                $link = trim((string) $item->link);
                $categories = [];

                foreach ($item->category as $category) {
                    $categories[] = trim((string) $category);
                }

                $postings[] = $this->posting(
                    externalId: trim((string) $item->guid) ?: $link,
                    url: $link ?: null,
                    title: (string) $item->title,
                    html: $html,
                    published: (string) $item->pubDate,
                    meta: ['categories' => $categories, 'kind' => 'feed_item'],
                );
            }

            return $postings;
        }

        $feed->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');

        foreach ($feed->xpath('//a:entry') ?: [] as $entry) {
            $entry->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
            $link = (string) ($entry->xpath('a:link[@rel="alternate" or not(@rel)]/@href')[0] ?? '');
            $html = trim((string) ($entry->content ?? '')) ?: trim((string) ($entry->summary ?? ''));

            $postings[] = $this->posting(
                externalId: trim((string) $entry->id) ?: $link,
                url: $link ?: null,
                title: (string) $entry->title,
                html: $html,
                published: (string) ($entry->published ?? $entry->updated),
                meta: ['kind' => 'feed_item'],
            );
        }

        return $postings;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function posting(string $externalId, ?string $url, string $title, string $html, string $published, array $meta): RawPostingData
    {
        $text = FetchPageTool::extractText('<html><body>'.$html.'</body></html>')['text'];

        // WordPress-feeds eindigen met "The post … appeared first on …": dat is geen opdrachttekst.
        $text = trim((string) preg_replace('/The post .* appeared first on .*$/u', '', $text));

        try {
            $date = new DateTimeImmutable($published !== '' ? $published : 'now');
        } catch (Throwable) {
            $date = new DateTimeImmutable;
        }

        return new RawPostingData(
            externalId: mb_substr($externalId, 0, 500),
            url: $url,
            title: trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            bodyText: $text,
            bodyHtml: $html !== '' ? $html : null,
            publishedAt: $date,
            meta: $meta,
        );
    }
}
