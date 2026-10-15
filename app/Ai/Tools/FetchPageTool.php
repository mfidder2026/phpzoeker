<?php

namespace App\Ai\Tools;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Haalt een openbare webpagina op voor website-onderzoek (ADR-10, R3): alleen domeinen van de
 * allowlist, met respect voor robots.txt, een rate limit per domein en 24 uur cache.
 * Geen inloggen, geen formulieren, geen captcha's.
 */
class FetchPageTool
{
    public const MAX_TEXT_LENGTH = 20_000;

    /**
     * @param  list<string>  $allowedDomains
     * @return array{url: string, title: string, text: string}
     *
     * @throws RuntimeException
     */
    public function fetch(string $url, array $allowedDomains): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Ongeldige URL.');
        }

        if (! self::isAllowed($host, $allowedDomains)) {
            throw new RuntimeException("Het domein {$host} staat niet op de lijst met toegestane domeinen.");
        }

        return Cache::remember('fetch-page:'.sha1($url), now()->addDay(), function () use ($url, $host, $scheme): array {
            if (! $this->robotsAllow($scheme, $host, (string) (parse_url($url, PHP_URL_PATH) ?: '/'))) {
                throw new RuntimeException("robots.txt van {$host} staat het ophalen van deze pagina niet toe.");
            }

            $key = 'fetch-page-domain:'.$host;

            if (RateLimiter::tooManyAttempts($key, 10)) {
                throw new RuntimeException("Rate limit voor {$host} bereikt; probeer het over een minuut opnieuw.");
            }

            RateLimiter::hit($key, 60);

            try {
                $response = Http::withUserAgent((string) config('phpzoeker.user_agent'))
                    ->timeout(20)
                    ->withOptions(['allow_redirects' => ['max' => 3]])
                    ->get($url);
            } catch (ConnectionException) {
                throw new RuntimeException("De pagina {$url} is niet bereikbaar.");
            }

            if (! $response->successful()) {
                throw new RuntimeException("De pagina gaf HTTP {$response->status()} terug.");
            }

            return ['url' => $url, ...self::extractText($response->body())];
        });
    }

    /**
     * @param  list<string>  $allowedDomains
     */
    public static function isAllowed(string $host, array $allowedDomains): bool
    {
        foreach ($allowedDomains as $domain) {
            $domain = strtolower(trim($domain));
            $domain = (string) preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');

            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{title: string, text: string}
     */
    public static function extractText(string $html): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.str_replace('<', ' <', $html), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//form') ?: [] as $node) {
            if ($node instanceof DOMNode) {
                $node->parentNode?->removeChild($node);
            }
        }

        $title = trim((string) $xpath->evaluate('string(//title)'));
        // Hoofdinhoud als die er is (menu's en kop/voet vallen dan weg), anders de hele body.
        $main = $xpath->query('//main|//article');
        $body = ($main !== false ? $main->item(0) : null) ?? $document->getElementsByTagName('body')->item(0);
        $text = $body instanceof DOMNode ? $body->textContent : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return ['title' => $title, 'text' => mb_substr($text, 0, self::MAX_TEXT_LENGTH)];
    }

    /**
     * Mag deze URL volgens robots.txt worden opgehaald door PHPZoeker?
     */
    public function allowedByRobots(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && $this->robotsAllow($scheme ?: 'https', $host, (string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    }

    private function robotsAllow(string $scheme, string $host, string $path): bool
    {
        $rules = Cache::remember("robots:{$host}", now()->addDay(), function () use ($scheme, $host): string {
            try {
                $response = Http::withUserAgent((string) config('phpzoeker.user_agent'))->timeout(10)->get("{$scheme}://{$host}/robots.txt");

                return $response->successful() ? $response->body() : '';
            } catch (ConnectionException) {
                return '';
            }
        });

        return self::robotsAllowsPath($rules, $path);
    }

    /**
     * Eenvoudige robots.txt-interpretatie: regels voor `*` en `PHPZoeker`; de langste
     * overeenkomende regel wint, Allow wint bij gelijke lengte.
     */
    public static function robotsAllowsPath(string $robots, string $path): bool
    {
        $applies = false;
        $best = ['length' => -1, 'allow' => true];

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                $applies = $value === '*' || stripos($value, 'phpzoeker') !== false;

                continue;
            }

            if (! $applies || ! in_array($field, ['allow', 'disallow'], true) || $value === '') {
                continue;
            }

            if (str_starts_with($path, $value)) {
                $length = strlen($value);

                if ($length > $best['length'] || ($length === $best['length'] && $field === 'allow')) {
                    $best = ['length' => $length, 'allow' => $field === 'allow'];
                }
            }
        }

        return $best['allow'];
    }
}
