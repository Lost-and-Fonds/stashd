<?php

declare(strict_types=1);

namespace App\Database;

use App\Auth\UserRole;
use App\Broadcasts\BroadcastItemState;
use App\Broadcasts\BroadcastState;
use App\Broadcasts\BroadcastTriggerRunState;
use App\Broadcasts\BroadcastTriggerState;
use App\Broadcasts\BroadcastTriggerType;
use App\MediaServers\MediaServerConnectionState;
use App\MediaServers\MediaServerType;
use App\Stashes\DownloadPolicy;
use App\Stashes\OrganizationMode;
use App\Stashes\StashInputState;
use App\Stashes\StashInputType;
use App\Stashes\StashItemState;
use App\Stashes\StashState;
use App\Stashes\SyncMode;
use App\System\Secret\SecretType;
use App\Vault\AssetKind;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemState;
use App\Vault\MetadataSnapshotType;
use App\Vault\UpstreamState;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\DatabaseIntegerSize;
use Tempest\Database\QueryStatements\OnDelete;

final class CreateDomainSchema implements MigratesUp
{
    use MigrationSchemaHelpers;

    public string $name = '2026_06_17_create_domain_schema';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            ...$this->tablesWithIndexes(
                $this->users(),
                $this->secrets(),
                $this->stashes(),
                $this->mediaServerConnections(),
                $this->stashInputs(),
                $this->mediaItems(),
                $this->stashItems(),
                $this->mediaItemSources(),
                $this->rawMetadataSnapshots(),
                $this->broadcasts(),
                $this->broadcastItems(),
                $this->broadcastTriggers(),
                $this->broadcastTriggerRuns(),
                $this->assets(),
                $this->apiTokens(),
            ),
        );
    }

    private function users(): CreateTableStatement
    {
        return $this->prefixedIdTable('users')
            ->string('email')
            ->string('username')
            ->string('passwordHash')
            ->enum('role', UserRole::class, default: UserRole::Admin)
            ->unique('email')
            ->unique('username')
            ->index('role');
    }

    private function secrets(): CreateTableStatement
    {
        return $this->prefixedIdTable('secrets')
            ->string('key')
            ->enum('type', SecretType::class)
            ->text('encryptedValue')
            ->string('nonce', length: 64)
            ->text('metadataJson', nullable: true)
            ->datetime('lastUsedAt', nullable: true)
            ->datetime('revokedAt', nullable: true)
            ->unique('key')
            ->index('type');
    }

    private function stashes(): CreateTableStatement
    {
        return $this->prefixedIdTable('stashes')
            ->string('name')
            ->string('slug')
            ->text('description', nullable: true)
            ->enum('syncMode', SyncMode::class, default: SyncMode::Automatic)
            ->enum('downloadPolicy', DownloadPolicy::class, default: DownloadPolicy::Video)
            ->string('videoQualityProfileId', length: 40, nullable: true)
            ->string('audioQualityProfileId', length: 40, nullable: true)
            ->enum('organizationMode', OrganizationMode::class, default: OrganizationMode::Flat)
            ->enum('state', StashState::class, default: StashState::Ready)
            ->unique('slug')
            ->index('state');
    }

    private function mediaServerConnections(): CreateTableStatement
    {
        return $this->prefixedIdTable('media_server_connections')
            ->enum('type', MediaServerType::class)
            ->string('name')
            ->text('baseUri')
            ->raw($this->fkColumn('tokenSecretId', 40, 'secrets', OnDelete::SET_NULL, nullable: true))
            ->text('settingsJson', nullable: true)
            ->enum('state', MediaServerConnectionState::class, default: MediaServerConnectionState::Ready)
            ->datetime('lastCheckedAt', nullable: true)
            ->text('lastError', nullable: true)
            ->index('type')
            ->index('state');
    }

    private function stashInputs(): CreateTableStatement
    {
        return $this->prefixedIdTable('stash_inputs')
            ->raw($this->fkColumn('stashId', 40, 'stashes', OnDelete::CASCADE))
            ->string('providerKey')
            ->enum('inputType', StashInputType::class)
            ->text('sourceUri')
            ->string('providerInputId')
            ->string('title', nullable: true)
            ->enum('state', StashInputState::class, default: StashInputState::Ready)
            ->enum('syncMode', SyncMode::class, nullable: true)
            ->datetime('lastCheckedAt', nullable: true)
            ->datetime('nextCheckAt', nullable: true)
            ->datetime('lastSuccessAt', nullable: true)
            ->datetime('lastFailureAt', nullable: true)
            ->integer('consecutiveFailures', default: 0)
            ->index('stashId')
            ->index('providerKey')
            ->unique('stashId', 'providerKey', 'providerInputId');
    }

    private function mediaItems(): CreateTableStatement
    {
        return $this->prefixedIdTable('media_items')
            ->string('providerKey')
            ->string('providerItemId')
            ->text('canonicalUri')
            ->string('title')
            ->text('description', nullable: true)
            ->string('creatorName', nullable: true)
            ->string('creatorProviderId', nullable: true)
            ->integer('durationSeconds', nullable: true)
            ->datetime('publishedAt', nullable: true)
            ->text('thumbnailUri', nullable: true)
            ->enum('state', MediaItemState::class, default: MediaItemState::Discovered)
            ->datetime('metadataCapturedAt', nullable: true)
            ->datetime('metadataRefreshedAt', nullable: true)
            ->datetime('lastSeenUpstreamAt', nullable: true)
            ->enum('upstreamState', UpstreamState::class, default: UpstreamState::Unknown)
            ->unique('providerKey', 'providerItemId')
            ->index('state')
            ->index('providerKey');
    }

    private function stashItems(): CreateTableStatement
    {
        return $this->prefixedIdTable('stash_items')
            ->raw($this->fkColumn('stashId', 40, 'stashes', OnDelete::CASCADE))
            ->raw($this->fkColumn('mediaItemId', 40, 'media_items', OnDelete::CASCADE))
            ->raw($this->fkColumn('stashInputId', 40, 'stash_inputs', OnDelete::SET_NULL, nullable: true))
            ->enum('state', StashItemState::class, default: StashItemState::Active)
            ->integer('position', nullable: true)
            ->integer('seasonNumber', nullable: true)
            ->integer('episodeNumber', nullable: true)
            ->string('seasonTitle', nullable: true)
            ->string('displayTitle', nullable: true)
            ->text('displayDescription', nullable: true)
            ->datetime('firstSeenAt', nullable: true)
            ->datetime('lastSeenAt', nullable: true)
            ->datetime('removedAt', nullable: true)
            ->string('removedReason', nullable: true)
            ->string('ignoredReason', nullable: true)
            ->index('stashId')
            ->index('mediaItemId')
            ->index('stashInputId')
            ->unique('stashId', 'mediaItemId');
    }

    private function mediaItemSources(): CreateTableStatement
    {
        return new CreateTableStatement('media_item_sources')
            ->raw('`id` VARCHAR(40) NOT NULL PRIMARY KEY')
            ->raw($this->fkColumn('mediaItemId', 40, 'media_items', OnDelete::CASCADE))
            ->raw($this->fkColumn('stashInputId', 40, 'stash_inputs', OnDelete::SET_NULL, nullable: true))
            ->string('providerKey')
            ->string('providerInputId')
            ->text('discoveredUri')
            ->datetime('discoveredAt')
            ->integer('position', nullable: true)
            ->integer('rawPosition', nullable: true)
            ->index('mediaItemId')
            ->index('stashInputId');
    }

    private function rawMetadataSnapshots(): CreateTableStatement
    {
        return $this->prefixedIdTableCreatedOnly('raw_metadata_snapshots')
            ->raw($this->fkColumn('mediaItemId', 40, 'media_items', OnDelete::CASCADE))
            ->raw($this->fkColumn('stashInputId', 40, 'stash_inputs', OnDelete::SET_NULL, nullable: true))
            ->string('providerKey')
            ->enum('snapshotType', MetadataSnapshotType::class)
            ->text('rawJson')
            ->index('mediaItemId')
            ->index('stashInputId')
            ->index('snapshotType');
    }

    private function broadcasts(): CreateTableStatement
    {
        return $this->prefixedIdTable('broadcasts')
            ->raw($this->fkColumn('stashId', 40, 'stashes', OnDelete::CASCADE))
            ->string('type')
            ->string('name')
            ->string('slug')
            ->enum('state', BroadcastState::class, default: BroadcastState::Pending)
            ->text('settingsJson', nullable: true)
            ->datetime('lastPlannedAt', nullable: true)
            ->datetime('lastBuiltAt', nullable: true)
            ->datetime('lastVerifiedAt', nullable: true)
            ->text('lastError', nullable: true)
            ->index('stashId')
            ->index('state')
            ->unique('stashId', 'slug');
    }

    private function broadcastItems(): CreateTableStatement
    {
        return $this->prefixedIdTable('broadcast_items')
            ->raw($this->fkColumn('broadcastId', 40, 'broadcasts', OnDelete::CASCADE))
            ->raw($this->fkColumn('stashItemId', 40, 'stash_items', OnDelete::CASCADE))
            ->raw($this->fkColumn('mediaItemId', 40, 'media_items', OnDelete::CASCADE))
            ->enum('state', BroadcastItemState::class, default: BroadcastItemState::Pending)
            ->text('publishedPath', nullable: true)
            ->text('publishedUri', nullable: true)
            ->datetime('lastPublishedAt', nullable: true)
            ->datetime('lastVerifiedAt', nullable: true)
            ->text('lastError', nullable: true)
            ->index('broadcastId')
            ->index('stashItemId')
            ->index('mediaItemId')
            ->unique('broadcastId', 'stashItemId');
    }

    private function broadcastTriggers(): CreateTableStatement
    {
        return $this->prefixedIdTable('broadcast_triggers')
            ->raw($this->fkColumn('broadcastId', 40, 'broadcasts', OnDelete::CASCADE))
            ->enum('type', BroadcastTriggerType::class)
            ->boolean('enabled', default: true)
            ->text('settingsJson', nullable: true)
            ->enum('state', BroadcastTriggerState::class, default: BroadcastTriggerState::Ready)
            ->datetime('lastTriggeredAt', nullable: true)
            ->datetime('lastSuccessAt', nullable: true)
            ->datetime('lastFailureAt', nullable: true)
            ->text('lastError', nullable: true)
            ->index('broadcastId')
            ->index('state');
    }

    private function broadcastTriggerRuns(): CreateTableStatement
    {
        return $this->prefixedIdTableCreatedOnly('broadcast_trigger_runs')
            ->raw($this->fkColumn('triggerId', 40, 'broadcast_triggers', OnDelete::CASCADE))
            ->string('reason', nullable: true)
            ->enum('state', BroadcastTriggerRunState::class, default: BroadcastTriggerRunState::Pending)
            ->datetime('startedAt', nullable: true)
            ->datetime('finishedAt', nullable: true)
            ->text('responseSummary', nullable: true)
            ->text('error', nullable: true)
            ->index('triggerId')
            ->index('state');
    }

    private function assets(): CreateTableStatement
    {
        return $this->prefixedIdTable('assets')
            ->raw($this->fkColumn('mediaItemId', 40, 'media_items', OnDelete::CASCADE, nullable: true))
            ->raw($this->fkColumn('broadcastId', 40, 'broadcasts', OnDelete::CASCADE, nullable: true))
            ->raw($this->fkColumn('broadcastItemId', 40, 'broadcast_items', OnDelete::CASCADE, nullable: true))
            ->enum('role', AssetRole::class)
            ->enum('kind', AssetKind::class)
            ->text('path', nullable: true)
            ->text('relativePath', nullable: true)
            ->string('mimeType', nullable: true)
            ->string('container', nullable: true)
            ->string('videoCodec', nullable: true)
            ->string('audioCodec', nullable: true)
            ->string('language', nullable: true)
            // BIGINT: byte counts overflow PostgreSQL's 32-bit INTEGER.
            // SQLite ignores the size and is already 64-bit, so its
            // compiled SQL (and migration hash) is unchanged.
            ->integer('sizeBytes', nullable: true, size: DatabaseIntegerSize::BIG)
            ->string('checksum', nullable: true)
            ->integer('durationSeconds', nullable: true)
            ->raw($this->fkColumn('derivedFromAssetId', 40, 'assets', OnDelete::SET_NULL, nullable: true))
            ->enum('state', AssetState::class, default: AssetState::Pending)
            ->datetime('lastVerifiedAt', nullable: true)
            ->datetime('missingAt', nullable: true)
            ->string('missingReason', nullable: true)
            ->index('mediaItemId')
            ->index('broadcastId')
            ->index('broadcastItemId')
            ->index('state')
            ->index('role');
    }

    private function apiTokens(): CreateTableStatement
    {
        return new CreateTableStatement('api_tokens')
            ->raw('`id` VARCHAR(40) NOT NULL PRIMARY KEY')
            ->raw($this->fkColumn('userId', 40, 'users', OnDelete::CASCADE))
            ->string('name')
            ->string('tokenHash')
            ->string('tokenPreview', nullable: true)
            ->text('scopesJson', nullable: true)
            ->datetime('lastUsedAt', nullable: true)
            ->datetime('expiresAt', nullable: true)
            ->datetime('createdAt', current: true)
            ->datetime('revokedAt', nullable: true)
            ->index('userId')
            ->unique('tokenHash');
    }
}
