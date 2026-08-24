<?php

declare(strict_types=1);

namespace App\System\Boot;

use App\Database\SupportedPostgresBaseline;
use RuntimeException;
use Tempest\Database\Database;
use Tempest\Database\Exceptions\QueryWasInvalid;
use Tempest\Database\Query;

final readonly class LegacyBaselineAdopter
{
    /** @var array<string, string> */
    public const LEGACY_MIGRATIONS = [
        '0000-00-00_create_migrations_table' => '5b7d89a96486bf63a129a48b6ad3c36f',
        '2026_06_16_create_foundation_schema' => '2b6ee673371362aafd28dc287908a180',
        '2026_06_17_create_domain_schema' => '010ebf5f8dfb81231a4c10439de7958f',
        '2026_06_18_phase2_execution' => '01b57ba7400459c2d625b953ac7816c8',
        '2026_06_19_add_podcast_broadcast_item_tokens' => '29410aca42dba97b6502b96306ba4136',
        '2026_06_20_add_stash_icon_uri' => '49c91cb7ba567d351829e7135c52a07f',
        '2026_06_21_add_media_item_content_type' => '406dca3f7107b7aa58bff06458c07c21',
        '2026_06_22_add_stash_input_options' => 'bdb7b2f4d6539c39b6b3e8e824fa38ea',
        '2026_06_22_create_sse_connections' => 'db28f8d5b937dd0cde68b5d741cd7c35',
        '2026_07_01_drop_user_username' => 'd5a43880f2a56c0e27be239c2b8e2316',
        '2026_07_02_drop_raw_metadata_snapshots' => '68d50088bd5f6a049199378519272cab',
        '2026_07_03_rename_json_columns' => '564c902f61f511b73acaa8af63289af0',
        '2026_07_04_replace_user_email_with_username' => '1e3378738e5e8fdd7d0948180c5cea8e',
        '2026_07_05_add_job_owner_token' => 'e047fa7de8fe1d402f0e8725389aecf3',
        '2026_07_05_drop_sse_and_event_notification_tables' => '64b753d41825dbeaa4877b55fd43fca0',
        '2026_07_11_add_job_workload_indexes' => 'e24b7080224a9e26063b0317b23339c1',
        '2026_07_11_add_secret_token_digest' => '8f86b0ba3be0bea2215d42d670ec78ae',
        '2026_07_11_create_login_attempts' => '0b1162f956d1f4e065c475038573cdc3',
        '2026_07_11_enforce_single_owner' => 'f636530978198286988562faa2d5cc14',
        '2026_07_14_create_broadcast_sponsorblock_refreshes' => 'd3dda9ffc19558a963fcf26c0683e8eb',
        '2026_07_14_create_media_timeline_entries' => '4170a81c3d7ad15106d93547457a4a23',
        '2026_07_15_drop_stash_slug' => '7e7d7d0ffaf98499e493cb80cd78ae4e',
    ];

    public function __construct(private Database $database) {}

    public function adopt(): void
    {
        try {
            $migrations = $this->database->fetch(new Query('SELECT name, hash FROM migrations ORDER BY id'));
        } catch (QueryWasInvalid $exception) {
            if ($this->database->dialect->isTableNotFoundError($exception)) {
                return;
            }

            throw $exception;
        }

        if ($migrations === [] || $this->hasBaseline($migrations)) {
            return;
        }

        if ($this->legacyMigrationsMatch($migrations) && $this->baselineSchemaMatches()) {
            $this->database->withinTransaction(function (): void {
                $this->database->execute(new Query(
                    'DELETE FROM migrations WHERE name <> ?',
                    bindings: ['0000-00-00_create_migrations_table'],
                ));
                $this->database->execute(new Query(
                    'INSERT INTO migrations (name, hash) VALUES (?, ?)',
                    bindings: [SupportedPostgresBaseline::NAME, SupportedPostgresBaseline::HASH],
                ));
            });

            return;
        }

        throw new RuntimeException(
            'This database predates Stashd\'s supported upgrade baseline or does not match the expected baseline schema. Upgrade through an intermediate version or restore a supported backup.',
        );
    }

    /** @param non-empty-array<int|string, mixed> $migrations */
    private function hasBaseline(array $migrations): bool
    {
        return in_array(SupportedPostgresBaseline::NAME, array_column($migrations, 'name'), true);
    }

    /** @param non-empty-array<int|string, mixed> $migrations */
    private function legacyMigrationsMatch(array $migrations): bool
    {
        return array_column($migrations, 'hash', 'name') === self::LEGACY_MIGRATIONS;
    }

    private function baselineSchemaMatches(): bool
    {
        $row = $this->database->fetchFirst(new Query(<<<'SQL'
            SELECT
                to_regclass('public.broadcast_sponsorblock_refreshes') IS NOT NULL
                AND to_regclass('public.media_timeline_entries') IS NOT NULL
                AND to_regclass('public.published_resources') IS NULL
                AND EXISTS (SELECT FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'broadcasts' AND column_name = 'tokenSecretId' AND is_nullable = 'YES')
                AND EXISTS (SELECT FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'broadcast_items' AND column_name = 'tokenSecretId' AND is_nullable = 'YES')
                AND NOT EXISTS (SELECT FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'assets' AND column_name = 'derivationKey')
                AND NOT EXISTS (SELECT FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'stashes' AND column_name = 'slug')
                AND EXISTS (SELECT FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'jobs_pending_claim')
                AND EXISTS (SELECT FROM pg_constraint WHERE conname = 'broadcasts_tokenSecretId_fkey')
                AS matches
            SQL));

        return in_array($row['matches'] ?? null, [true, 1, '1', 't'], true);
    }
}
