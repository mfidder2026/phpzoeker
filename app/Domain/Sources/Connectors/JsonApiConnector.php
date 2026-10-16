<?php

namespace App\Domain\Sources\Connectors;

use App\Ai\Tools\FetchPageTool;
use App\Domain\Sources\Contracts\SourceConnector;
use App\Domain\Sources\Data\ConnectorTestResult;
use App\Domain\Sources\Data\RawPostingData;
use App\Domain\Sources\Models\Source;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Openbare JSON-API van een bron (§8.1, route 1), bijv. TenderNed, een WordPress-REST-endpoint
 * of een vacature-API. Eén connector voor alle API's: per bron leg je in de configuratie vast waar
 * de lijst staat en welke velden de titel, link, tekst en datum zijn.
 *
 * Configuratie: `api_url`, `items_path` (punt-pad naar de lijst, leeg = de hele respons),
 * `fields.id|title|url|date` (punt-paden), `fields.body` (lijst van "Label=pad" of "pad"),
 * optioneel `url_template` (bijv. "https://site.nl/opdracht/{id}"), `skip_first` en `max_items`.
 */
class JsonApiConnector implements SourceConnector
{
    public function __construct(private readonly FetchPageTool $pages) {}

    public function key(): string
    {
        return 'api';
    }

    public function fetch(Source $source, ?DateTimeImmutable $since): iterable
    {
        $url = (string) ($source->configValue('api_url') ?? '');

        if ($url === '') {
            throw new RuntimeException('Er is geen API-URL ingesteld.');
        }

        if (! $this->pages->allowedByRobots($url)) {
            throw new RuntimeException('robots.txt staat het ophalen van deze API niet toe.');
        }

        try {
            $response = Http::withUserAgent((string) config('phpzoeker.user_agent'))->acceptJson()->timeout(30)->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException("De API {$url} is niet bereikbaar.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("De API gaf HTTP {$response->status()} terug.");
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('De API gaf geen JSON terug.');
        }

        /** @var array<string, mixed> $config */
        $config = $source->config ?? [];

        return $this->parse($data, $config);
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
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $config
     * @return list<RawPostingData>
     */
    public function parse(array $data, array $config): array
    {
        $itemsPath = (string) ($config['items_path'] ?? '');
        $items = $itemsPath === '' ? $data : data_get($data, $itemsPath);

        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException("Op het pad \"{$itemsPath}\" staat geen lijst met publicaties.");
        }

        /** @var array<string, mixed> $fields */
        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $items = array_slice($items, max(0, (int) ($config['skip_first'] ?? 0)), max(1, (int) ($config['max_items'] ?? 50)));
        $postings = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->text($item, $fields['title'] ?? 'title');
            $id = $this->text($item, $fields['id'] ?? 'id');
            $url = $this->text($item, $fields['url'] ?? 'url');

            if ($url === '' && filled($config['url_template'] ?? null)) {
                $url = str_replace('{id}', rawurlencode($id), (string) $config['url_template']);
            }

            if ($title === '' || ($id === '' && $url === '')) {
                continue;
            }

            $html = collect(Arr::wrap($fields['body'] ?? ['description']))
                ->map(function (mixed $entry) use ($item): ?string {
                    [$label, $path] = str_contains((string) $entry, '=') ? explode('=', (string) $entry, 2) : [null, (string) $entry];
                    $value = $this->text($item, $path);

                    return $value === '' ? null : ($label !== null ? '<p><strong>'.e($label).':</strong> '.e($value).'</p>' : $value);
                })
                ->filter()
                ->implode("\n");

            $postings[] = new RawPostingData(
                externalId: mb_substr($id !== '' ? $id : $url, 0, 500),
                url: $url !== '' ? $url : null,
                title: trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                bodyText: trim(FetchPageTool::extractText('<html><body>'.$html.'</body></html>')['text']),
                bodyHtml: $html !== '' ? $html : null,
                publishedAt: $this->date($this->text($item, $fields['date'] ?? 'date')),
                meta: ['kind' => 'api_item'],
            );
        }

        return $postings;
    }

    /**
     * @param  array<mixed>  $item
     */
    private function text(array $item, mixed $path): string
    {
        $value = data_get($item, (string) $path);

        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $value)));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function date(string $value): DateTimeImmutable
    {
        if ($value === '') {
            return new DateTimeImmutable;
        }

        try {
            return is_numeric($value) ? (new DateTimeImmutable)->setTimestamp((int) $value) : new DateTimeImmutable($value);
        } catch (Throwable) {
            return new DateTimeImmutable;
        }
    }
}
