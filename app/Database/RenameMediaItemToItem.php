<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class RenameMediaItemToItem implements MigratesUp
{
    public string $name = '2026_09_08_rename_media_item_to_item';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE `media_item_sources` RENAME TO `item_sources`'),
            new MigrationSqlStatement('ALTER TABLE `media_items` RENAME TO `items`'),
            new MigrationSqlStatement('ALTER TABLE `assets` RENAME COLUMN `mediaItemId` TO `itemId`'),
            new MigrationSqlStatement('ALTER TABLE `broadcast_items` RENAME COLUMN `mediaItemId` TO `itemId`'),
            new MigrationSqlStatement('ALTER TABLE `item_sources` RENAME COLUMN `mediaItemId` TO `itemId`'),
            new MigrationSqlStatement('ALTER TABLE `media_timeline_entries` RENAME COLUMN `mediaItemId` TO `itemId`'),
            new MigrationSqlStatement('ALTER TABLE `stash_items` RENAME COLUMN `mediaItemId` TO `itemId`'),
            new MigrationSqlStatement('ALTER TABLE `activity_events` RENAME COLUMN `mediaItemId` TO `itemId`'),
            new MigrationSqlStatement('ALTER INDEX `assets_media_item_id` RENAME TO `assets_item_id`'),
            new MigrationSqlStatement('ALTER INDEX `broadcast_items_media_item_id` RENAME TO `broadcast_items_item_id`'),
            new MigrationSqlStatement('ALTER INDEX `media_item_sources_media_item_id` RENAME TO `item_sources_item_id`'),
            new MigrationSqlStatement('ALTER INDEX `media_items_provider_key` RENAME TO `items_provider_key`'),
            new MigrationSqlStatement('ALTER INDEX `media_items_provider_key_provider_item_id` RENAME TO `items_provider_key_provider_item_id`'),
            new MigrationSqlStatement('ALTER INDEX `media_items_state` RENAME TO `items_state`'),
            new MigrationSqlStatement('ALTER INDEX `media_timeline_entries_media_item_id` RENAME TO `media_timeline_entries_item_id`'),
            new MigrationSqlStatement('ALTER INDEX `media_timeline_entries_media_item_id_source_external_id` RENAME TO `media_timeline_entries_item_id_source_external_id`'),
            new MigrationSqlStatement('ALTER INDEX `stash_items_media_item_id` RENAME TO `stash_items_item_id`'),
            new MigrationSqlStatement('ALTER INDEX `stash_items_stash_id_media_item_id` RENAME TO `stash_items_stash_id_item_id`'),
            new MigrationSqlStatement('ALTER TABLE `assets` RENAME CONSTRAINT `assets_mediaItemId_fkey` TO `assets_itemId_fkey`'),
            new MigrationSqlStatement('ALTER TABLE `broadcast_items` RENAME CONSTRAINT `broadcast_items_mediaItemId_fkey` TO `broadcast_items_itemId_fkey`'),
            new MigrationSqlStatement('ALTER TABLE `item_sources` RENAME CONSTRAINT `media_item_sources_mediaItemId_fkey` TO `item_sources_itemId_fkey`'),
            new MigrationSqlStatement('ALTER TABLE `media_timeline_entries` RENAME CONSTRAINT `media_timeline_entries_mediaItemId_fkey` TO `media_timeline_entries_itemId_fkey`'),
            new MigrationSqlStatement('ALTER TABLE `stash_items` RENAME CONSTRAINT `stash_items_mediaItemId_fkey` TO `stash_items_itemId_fkey`'),
        );
    }
}
