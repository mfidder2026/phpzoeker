<?php

namespace App\Domain\Sources\Connectors;

use App\Ai\Tools\FetchPageTool;
use App\Domain\Sources\Contracts\SourceConnector;
use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Data\RawPostingData;
use App\Domain\Sources\Models\Source;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Openbare overzichtspagina met opdrachten (§8.1, route 3), voor bronnen zonder feed of API,
 * zoals OverheidZZP. Leest één (of enkele) overzichtspagina's zonder in te loggen (R3), met
 * respect voor robots.txt en een herkenbare user agent (§8.4). Per opdracht komen de link, de
 * titel en de tekst van de kaart mee; de verwerking haalt daarna de volledige openbare pagina op.
 *
 * Configuratie: `listing_urls` (lijst), `link_pattern` (regex op het pad van de opdrachtlinks)
 * en optioneel `exclude_patterns` en `max_items`.
 */
class HtmlListingConnector implements SourceConnector
{
    /** Tekst van knoppen die geen titel zijn. */
    private const BUTTON_TEXTS = ['bekijk opdracht', 'bekijk vacature', 'lees meer', 'meer informatie', 'bekijk', 'details'];

    public function __construct(private readonly FetchPageTool $pages) {}

    public function key(): string
    {
        return 'html';
    }

    public function fetch(Source $source, ?DateTimeImmutable $since): iterable
    {
        $urls = array_values(array_filter((array) ($source->configValue('listing_urls') ?? [$source->base_url]), 'is_string'));

        if ($urls === []) {
            throw new RuntimeException('Er is geen overzichtspagina ingesteld.');
        }

        $pattern = (string) ($source->configValue('link_pattern') ?? '');

        if ($pattern === '' || @preg_match($pattern, '') === false) {
            throw new RuntimeException('Het linkpatroon van de bron is leeg of ongeldig.');
        }

        $exclude = array_values(array_filter((array) ($source->configValue('exclude_patterns') ?? []), 'is_string'));
        $max = max(1, (int) ($source->configValue('max_items') ?? 50));
        $postings = [];

        foreach ($urls as $url) {
            foreach ($this->parse($this->download($url), $url, $pattern, $exclude) as $posting) {
                $postings[$posting->externalId] ??= $posting;
            }
        }

        return array_slice(array_values($postings), 0, $max);
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
     * @param  list<string>  $exclude
     * @return list<RawPostingData>
     */
    public function parse(string $html, string $pageUrl, string $pattern, array $exclude = []): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR);
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $found = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $url = $this->absolute(html_entity_decode($anchor->getAttribute('href')), $pageUrl);

            if ($url === null) {
                continue;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);

            if (parse_url($url, PHP_URL_HOST) !== parse_url($pageUrl, PHP_URL_HOST) || preg_match($pattern, $path) !== 1) {
                continue;
            }

            if (collect($exclude)->contains(fn (string $excluded): bool => @preg_match($excluded, $path) === 1)) {
                continue;
            }

            $key = rtrim(strtok($url, '?#') ?: $url, '/').'/';
            $text = $this->clean($anchor->textContent);
            $found[$key] ??= ['title' => null, 'card' => ''];

            if ($text !== '' && ! in_array(mb_strtolower($text), self::BUTTON_TEXTS, true) && mb_strlen($text) <= 200 && $found[$key]['title'] === null) {
                $found[$key]['title'] = $text;
            }

            $card = $this->cardText($anchor);

            if (mb_strlen($card) > mb_strlen($found[$key]['card'])) {
                $found[$key]['card'] = $card;
            }
        }

        $postings = [];

        foreach ($found as $url => $item) {
            $title = $item['title'] ?? Str::of((string) basename((string) parse_url($url, PHP_URL_PATH)))->replace('-', ' ')->ucfirst()->toString();

            $postings[] = new RawPostingData(
                externalId: mb_substr($url, 0, 500),
                url: $url,
                title: $title,
                bodyText: $item['card'],
                bodyHtml: null,
                publishedAt: new DateTimeImmutable,
                meta: ['kind' => 'listing_item', 'listing_url' => $pageUrl],
            );
        }

        return $postings;
    }

    private function download(string $url): string
    {
        if (! $this->pages->allowedByRobots($url)) {
            throw new RuntimeException("robots.txt staat het ophalen van {$url} niet toe.");
        }

        try {
            $response = Http::withUserAgent((string) config('phpzoeker.user_agent'))->timeout(30)->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException("De overzichtspagina {$url} is niet bereikbaar.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("De overzichtspagina gaf HTTP {$response->status()} terug.");
        }

        return $response->body();
    }

    /**
     * Tekst van de kaart rond de link: het kleinste omringende blok met meer dan alleen de link,
     * maar zonder de rest van de pagina (maximaal 800 tekens).
     */
    private function cardText(DOMElement $anchor): string
    {
        $node = $anchor;
        $best = $this->clean($anchor->textContent);

        for ($level = 0; $level < 6 && $node->parentNode instanceof DOMElement; $level++) {
            $node = $node->parentNode;
            $text = $this->clean($node->textContent);

            if (mb_strlen($text) > 800) {
                break;
            }

            $best = $text;
        }

        return $best;
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function absolute(string $href, string $base): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        $host = (string) parse_url($base, PHP_URL_HOST);

        return str_starts_with($href, '/') ? "{$scheme}://{$host}{$href}" : rtrim(dirname((string) parse_url($base, PHP_URL_PATH)), '/').'/'.$href;
    }
}
