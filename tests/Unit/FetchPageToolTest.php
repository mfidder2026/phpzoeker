<?php

use App\Ai\Tools\FetchPageTool;

it('only allows domains on the allowlist, including subdomains', function () {
    $allowed = ['fremen.app', 'https://www.headfirst.nl/'];

    expect(FetchPageTool::isAllowed('fremen.app', $allowed))->toBeTrue()
        ->and(FetchPageTool::isAllowed('opdrachten.fremen.app', $allowed))->toBeTrue()
        ->and(FetchPageTool::isAllowed('www.headfirst.nl', $allowed))->toBeTrue()
        ->and(FetchPageTool::isAllowed('evilfremen.app', $allowed))->toBeFalse()
        ->and(FetchPageTool::isAllowed('linkedin.com', $allowed))->toBeFalse();
});

it('respects robots.txt', function (string $path, bool $allowed) {
    $robots = <<<'TXT'
        User-agent: *
        Disallow: /private
        Allow: /private/public
        Disallow: /search

        User-agent: SomeOtherBot
        Disallow: /
        TXT;

    expect(FetchPageTool::robotsAllowsPath($robots, $path))->toBe($allowed);
})->with([
    ['/', true],
    ['/vacatures/123', true],
    ['/private/x', false],
    ['/private/public/page', true],
    ['/search?q=php', false],
]);

it('extracts readable text without scripts', function () {
    $page = FetchPageTool::extractText('<html><head><title>Opdracht</title><script>alert(1)</script></head><body><nav>menu</nav><h1>PHP developer</h1><p>40   uur</p></body></html>');

    expect($page['title'])->toBe('Opdracht')
        ->and($page['text'])->toBe('PHP developer 40 uur');
});
