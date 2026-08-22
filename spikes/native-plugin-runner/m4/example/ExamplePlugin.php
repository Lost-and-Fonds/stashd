<?php

declare(strict_types=1);

namespace Stashd\ExamplePlugin;

use Stashd\PluginSdk\AcquisitionOptions;
use Stashd\PluginSdk\AcquisitionResult;
use Stashd\PluginSdk\BroadcastPlugin;
use Stashd\PluginSdk\Choice;
use Stashd\PluginSdk\DiscoveryIntent;
use Stashd\PluginSdk\DiscoveredItem;
use Stashd\PluginSdk\FinalizationRequest;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\InputPlugin;
use Stashd\PluginSdk\MediaKind;
use Stashd\PluginSdk\OperationRequest;
use Stashd\PluginSdk\OperationResult;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\PluginEntrypoint;
use Stashd\PluginSdk\PluginError;
use Stashd\PluginSdk\PluginErrorCode;
use Stashd\PluginSdk\PluginFailure;
use Stashd\PluginSdk\PluginFailureException;
use Stashd\PluginSdk\PluginRegistry;
use Stashd\PluginSdk\Preparation;
use Stashd\PluginSdk\PublishRequest;
use Stashd\PluginSdk\Publication;
use Stashd\PluginSdk\PublishedFile;
use Stashd\PluginSdk\ResolvedInput;
use Stashd\PluginSdk\StagedArtifact;
use Stashd\PluginSdk\Setting;
use Stashd\PluginSdk\Artifact;

final class ExampleBroadcast implements BroadcastPlugin
{
    public function __construct(private PluginContext $context) {}

    public function prepare(PublishRequest $request): Preparation
    {
        $this->context->progress->report('prepare');
        return new Preparation();
    }

    public function publish(PublishRequest $request): Publication
    {
        $this->context->logger->info('publishing example items');
        $this->context->progress->report('publish');
        $files = array_map(
            static fn (Item $item): PublishedFile => new PublishedFile($item->id, $item->sourceReference ?? '', 'items/' . rawurlencode($item->id) . '.bin'),
            $request->items,
        );

        return new Publication(
            new Artifact('example-artifact:' . $request->reference, 'application/octet-stream'),
            $files,
            [new Setting('item-count', OptionValue::number(count($request->items)))],
        );
    }

    public function finalize(FinalizationRequest $request): Publication
    {
        $this->context->progress->report('finalize');
        return $request->publication;
    }

    public function operation(OperationRequest $request): OperationResult
    {
        if ($request->name === 'fail') {
            throw new PluginFailureException(new PluginFailure(
                PluginErrorCode::Unavailable,
                new PluginError('example operation unavailable', true),
            ));
        }

        return new OperationResult([new Choice('example', 'Example')], $request->payload);
    }
}

final class ExampleInput implements InputPlugin
{
    public function resolve(string $source): ResolvedInput
    {
        return new ResolvedInput('example-input', 'example:' . $source, 'example', 'Example source');
    }

    public function discover(string $inputId, DiscoveryIntent $intent, array $options = []): array
    {
        return [new DiscoveredItem('example-item', 'example:item', 'Example item', kind: 'binary')];
    }

    public function acquire(DiscoveredItem $item, AcquisitionOptions $options): AcquisitionResult
    {
        return new AcquisitionResult([new StagedArtifact('example:' . $item->id, 'application/octet-stream')]);
    }
}

final class ExampleEntrypoint implements PluginEntrypoint
{
    public function __construct(private PluginContext $context) {}

    public function register(PluginRegistry $registry): void
    {
        $registry->broadcast('example-broadcast', new ExampleBroadcast($this->context));
        $registry->input('example-input', new ExampleInput());
    }
}
