<?php

declare(strict_types=1);

namespace PlexNative;

use RuntimeException;
use Stashd\PluginSdk as Sdk;

final class PlexBroadcast implements Sdk\BroadcastPlugin
{
    public function prepare(Sdk\PublishRequest $request): Sdk\Preparation
    {
        return new Sdk\Preparation();
    }

    public function publish(Sdk\PublishRequest $request): Sdk\Publication
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8', 'yes');
        $writer->startElement('tvshow');
        $writer->writeElement('title', $this->settingText($request->settings, 'title') ?? 'Stashd Library');
        $writer->endElement();
        $writer->endDocument();
        $nfo = $writer->outputMemory();
        $staging = $request->staging ?? throw new RuntimeException('Plex staging is not available');
        $artifact = $staging->write('tvshow.nfo', $nfo, 'application/xml');

        $files = [];
        foreach ($request->items as $index => $item) {
            $video = $this->resource($item, 'video');
            if ($video === null) {
                continue;
            }
            $season = $this->season($request, $item->sourceReference);
            $title = $this->sanitize($item->title);
            $episode = $index + 1;
            $extension = $this->mediaExtension($video->mediaType);
            $base = sprintf('Season %02d/S%02dE%03d - %s', $season, $season, $episode, $title);
            $files[] = new Sdk\PublishedFile($item->id, $video->reference, $base . '.' . $extension);

            if (($this->settingText($request->settings, 'captions') ?? 'off') !== 'off') {
                $subtitle = $this->resource($item, 'subtitle');
                if ($subtitle !== null) {
                    $language = $this->captionLanguage($request->settings);
                    $files[] = new Sdk\PublishedFile($item->id, $subtitle->reference, $base . '.' . $language . '.vtt');
                }
            }
        }

        return new Sdk\Publication(new Sdk\Artifact($artifact->reference, $artifact->mediaType, $artifact->sizeBytes), $files);
    }

    public function finalize(Sdk\FinalizationRequest $request, Sdk\PluginContext $context): Sdk\Publication
    {
        $server = $this->settingText($request->request->settings, 'server_url');
        if ($server === null) {
            throw new RuntimeException('Plex server URL is not configured');
        }
        $library = $this->library($request->request->settings);
        $response = $context->http->request(
            'GET',
            rtrim($server, '/') . '/library/sections/' . rawurlencode($library) . '/refresh',
            [],
            null,
            $this->credential($request->request->settings),
        );
        $this->requireSuccess($response->status, 'Plex refresh');
        $context->progress->report('remote refresh complete');

        return $request->publication;
    }

    public function operation(Sdk\OperationRequest $request, Sdk\PluginContext $context): Sdk\OperationResult
    {
        $server = $this->settingText($request->settings, 'server_url');
        if ($server === null) {
            throw new RuntimeException('Plex server URL is not configured');
        }
        $path = match ($request->name) {
            'test-connection' => '/identity',
            'discover-libraries' => '/library/sections',
            'refresh-library' => '/library/sections/' . rawurlencode($this->library($request->settings)) . '/refresh',
            default => throw new RuntimeException('Unsupported external operation'),
        };
        $response = $context->http->request('GET', rtrim($server, '/') . $path, [], null, $this->credential($request->settings));
        $this->requireSuccess($response->status, 'Plex request');
        if ($request->name === 'refresh-library') {
            return new Sdk\OperationResult(values: [new Sdk\Setting('ok', Sdk\OptionValue::text('true'))]);
        }
        $xml = $this->parseXml($response->body());
        if ($request->name === 'test-connection') {
            return new Sdk\OperationResult(values: [
                new Sdk\Setting('ok', Sdk\OptionValue::text('true')),
                new Sdk\Setting('message', Sdk\OptionValue::text('Plex connection OK.')),
                new Sdk\Setting('server_name', Sdk\OptionValue::text('Plex')),
            ]);
        }
        $choices = [];
        foreach ($xml->Directory as $directory) {
            $value = trim((string) ($directory['key'] ?? ''));
            if ($value === '') {
                continue;
            }
            $label = trim((string) ($directory['title'] ?? '')) ?: 'Library';
            $choices[] = new Sdk\Choice($value, $label);
        }

        return new Sdk\OperationResult($choices);
    }

    /** @param list<Sdk\Setting> $settings */
    private function settingText(array $settings, string $key): ?string
    {
        foreach ($settings as $setting) {
            if ($setting->key === $key && $setting->value->kind === 'text') {
                return (string) $setting->value->value;
            }
        }

        return null;
    }

    /** @param list<Sdk\Setting> $settings */
    private function settingNumber(array $settings, string $key): ?int
    {
        foreach ($settings as $setting) {
            if ($setting->key === $key && $setting->value->kind === 'number') {
                return (int) $setting->value->value;
            }
        }

        return null;
    }

    /** @param list<Sdk\Setting> $settings */
    private function credential(array $settings): string
    {
        return $this->settingText($settings, 'credential_name') ?? 'plex-api-token';
    }

    /** @param list<Sdk\Setting> $settings */
    private function library(array $settings): string
    {
        return $this->settingText($settings, 'library_id')
            ?? $this->settingText($settings, 'libraryId')
            ?? throw new RuntimeException('Plex library is not configured');
    }

    private function parseXml(string $body): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $xml instanceof \SimpleXMLElement || $xml->getName() !== 'MediaContainer') {
            throw new RuntimeException('Plex returned invalid XML');
        }

        return $xml;
    }

    private function requireSuccess(int $status, string $operation): void
    {
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($operation . ' returned HTTP ' . $status);
        }
    }

    private function resource(Sdk\Item $item, string $kind): ?Sdk\ItemResource
    {
        foreach ($item->resources as $resource) {
            if ($resource->kind === $kind) {
                return $resource;
            }
        }

        return null;
    }

    /** @param list<Sdk\Source> $sources */
    private function season(Sdk\PublishRequest $request, ?string $sourceReference): int
    {
        foreach ($request->sources as $source) {
            if ($source->reference === $sourceReference) {
                return max(1, $this->settingNumber($source->settings, 'season') ?? 1);
            }
        }

        return 1;
    }

    /** @param list<Sdk\Setting> $settings */
    private function captionLanguage(array $settings): string
    {
        $value = $this->settingText($settings, 'caption_languages') ?? 'und';
        $language = trim(explode(',', $value, 2)[0]);

        return $language !== '' ? $language : 'und';
    }

    private function mediaExtension(?string $mediaType): string
    {
        return match ($mediaType) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => 'mp4',
        };
    }

    private function sanitize(string $value): string
    {
        $value = strtr($value, ['/' => '_', '\\' => '_', ':' => '_', '*' => '_', '?' => '_', '"' => '_', '<' => '_', '>' => '_', '|' => '_']);
        $value = trim($value, " .\t\n\r\0\x0B");

        return substr($value !== '' ? $value : 'untitled', 0, 180);
    }
}
