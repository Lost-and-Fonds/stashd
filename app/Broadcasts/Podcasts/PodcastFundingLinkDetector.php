<?php

declare(strict_types=1);

namespace App\Broadcasts\Podcasts;

/**
 * Conservative v1 funding-link detector.
 *
 * Scans plain-text/Markdown description text for links to a small set of
 * well-known creator funding platforms. Channel/about-page scraping and
 * membership, Nebula, creator-site, and merch-store detection are deferred —
 * see docs/broadcasts/README.md.
 */
final readonly class PodcastFundingLinkDetector
{
    /** @var list<string> */
    private const array PRIORITY = [
        'patreon',
        'kofi',
        'github_sponsors',
        'buymeacoffee',
    ];

    private const string TRAILING_PUNCTUATION = ".,)]!?'\"";

    /**
     * @param list<string|null> $candidateTexts
     */
    public function detect(array $candidateTexts): ?string
    {
        $foundByCategory = [];

        foreach ($candidateTexts as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            foreach ($this->extractUrls($text) as $url) {
                $category = $this->categorize($url);

                if ($category !== null && ! isset($foundByCategory[$category])) {
                    $foundByCategory[$category] = $url;
                }
            }
        }

        foreach (self::PRIORITY as $category) {
            if (isset($foundByCategory[$category])) {
                return $foundByCategory[$category];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function extractUrls(string $text): array
    {
        preg_match_all('/https?:\/\/[^\s<>]+/i', $text, $matches);

        return array_map($this->normalize(...), $matches[0] ?? []);
    }

    private function normalize(string $url): string
    {
        $url = rtrim($url, self::TRAILING_PUNCTUATION);

        if (str_starts_with(strtolower($url), 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        return $url;
    }

    private function categorize(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return match (true) {
            in_array($host, ['patreon.com', 'www.patreon.com'], true) => 'patreon',
            in_array($host, ['ko-fi.com', 'www.ko-fi.com'], true) => 'kofi',
            in_array($host, ['github.com', 'www.github.com'], true) && preg_match('#^/sponsors/[^/]+#', $path) === 1 => 'github_sponsors',
            in_array($host, ['buymeacoffee.com', 'www.buymeacoffee.com'], true) => 'buymeacoffee',
            default => null,
        };
    }
}
