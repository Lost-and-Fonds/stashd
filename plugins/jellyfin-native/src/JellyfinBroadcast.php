<?php

declare(strict_types=1);

namespace JellyfinNative;

use RuntimeException;
use Stashd\PluginSdk\Artifact;
use Stashd\PluginSdk\BroadcastPlugin;
use Stashd\PluginSdk\Choice;
use Stashd\PluginSdk\FinalizationRequest;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\OperationRequest;
use Stashd\PluginSdk\OperationResult;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\Preparation;
use Stashd\PluginSdk\Publication;
use Stashd\PluginSdk\PublishedFile;
use Stashd\PluginSdk\PublishRequest;
use Stashd\PluginSdk\Setting;

final class JellyfinBroadcast implements BroadcastPlugin
{
    public function prepare(PublishRequest $request): Preparation
    {
        return new Preparation();
    }

    public function publish(PublishRequest $request): Publication
    {
        $files = [];
        foreach ($request->items as $item) {
            $resource = $this->videoResource($item);
            if ($resource === null) {
                continue;
            }
            $index = $this->itemIndex($item, $request->items);
            $files[] = new PublishedFile(
                $item->id,
                $resource->reference,
                'Season 01/S01E' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . ' - ' . $this->sanitize($item->title) . '.mp4',
            );
        }

        return new Publication(new Artifact(''), $files);
    }

    public function finalize(FinalizationRequest $request, PluginContext $context): Publication
    {
        $server = $this->setting($request->request->settings, 'server_url');
        if ($server === null) {
            throw new RuntimeException('Jellyfin server URL is not configured');
        }
        $response = $context->http->request('POST', rtrim($server, '/') . '/Library/Refresh', [], null, $this->credential($request->request->settings));
        $this->requireSuccess($response->status, 'Jellyfin refresh');
        $context->progress->report('remote refresh complete');

        return $request->publication;
    }

    public function operation(OperationRequest $request, PluginContext $context): OperationResult
    {
        $server = $this->setting($request->settings, 'server_url');
        if ($server === null) {
            throw new RuntimeException('Jellyfin server URL is not configured');
        }
        $path = match ($request->name) {
            'test-connection' => '/System/Info/Public',
            'discover-libraries' => '/Library/MediaFolders',
            'refresh-library' => '/Library/Refresh',
            default => throw new RuntimeException('Unsupported external operation'),
        };
        $method = $request->name === 'refresh-library' ? 'POST' : 'GET';
        $response = $context->http->request($method, rtrim($server, '/') . $path, [], null, $this->credential($request->settings));
        $this->requireSuccess($response->status, 'Jellyfin request');
        if ($request->name === 'refresh-library') {
            return new OperationResult(values: [new Setting('ok', OptionValue::text('true'))]);
        }
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Jellyfin returned invalid JSON', 0, $exception);
        }
        if (! is_array($data)) {
            throw new RuntimeException('Jellyfin returned invalid JSON');
        }
        if ($request->name === 'test-connection') {
            return new OperationResult(values: [
                new Setting('ok', OptionValue::text('true')),
                new Setting('message', OptionValue::text('Jellyfin connection OK.')),
                new Setting('server_name', OptionValue::text((string) ($data['ServerName'] ?? 'Jellyfin'))),
                new Setting('version', OptionValue::text((string) ($data['Version'] ?? ''))),
            ]);
        }
        $choices = [];
        foreach (is_array($data['Items'] ?? null) ? $data['Items'] : [] as $item) {
            if (is_array($item) && isset($item['Id'])) {
                $choices[] = new Choice((string) $item['Id'], (string) ($item['Name'] ?? 'Library'));
            }
        }

        return new OperationResult($choices);
    }

    /** @param list<Setting> $settings */
    private function setting(array $settings, string $key): ?string
    {
        foreach ($settings as $setting) {
            if ($setting->key === $key && $setting->value->kind === 'text') {
                return (string) $setting->value->value;
            }
        }

        return null;
    }

    /** @param list<Setting> $settings */
    private function credential(array $settings): string
    {
        return $this->setting($settings, 'credential_name') ?? 'jellyfin-api-token';
    }

    private function requireSuccess(int $status, string $operation): void
    {
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($operation . ' returned HTTP ' . $status);
        }
    }

    private function videoResource(Item $item): ?\Stashd\PluginSdk\ItemResource
    {
        foreach ($item->resources as $resource) {
            if ($resource->kind === 'video') {
                return $resource;
            }
        }

        return null;
    }

    /** @param list<Item> $items */
    private function itemIndex(Item $item, array $items): int
    {
        foreach ($items as $index => $candidate) {
            if ($candidate->id === $item->id) {
                return $index + 1;
            }
        }

        return 1;
    }

    private function sanitize(string $value): string
    {
        $value = strtr($value, ['/' => '_', '\\' => '_', ':' => '_', '*' => '_', '?' => '_', '"' => '_', '<' => '_', '>' => '_', '|' => '_']);

        return substr(trim($value, " .\t\n\r\0\x0B"), 0, 180);
    }
}
