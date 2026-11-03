<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Integration\AcquisitionStore;
use App\Integration\BroadcastCoordinator;
use App\Integration\DiscoveryStore;
use App\Integration\DTO\BroadcastSelection;
use App\Integration\IntegrationConfigurations;
use App\Integration\OperationsDatabase;
use App\RecordsAndArchives\ItemArchive;
use App\RecordsAndArchives\Vault;
use Generator;
use InvalidArgumentException;
use RuntimeException;
use Tempest\Database\Query;

/**
 * Relate collections to existing Integration identities and verified Vault revisions.
 */
final readonly class Catalogue
{
    /**
     * Reuse Integration persistence and archive readers; opening the service performs no work.
     */
    public function __construct(
        private OperationsDatabase $database,
        private IntegrationConfigurations $configurations,
        private DiscoveryStore $discovery,
        private AcquisitionStore $acquisition,
        private ItemArchive $archive,
        private Vault $vault,
        private BroadcastCoordinator $broadcast,
    ) {}

    /**
     * Persist a named collection and return its UUID without touching sources or archives.
     */
    public function create(string $name): string
    {
        if (trim($name) === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('A Stash name requires 1 to 255 bytes.');
        }

        $this->database->connect();
        $stash = new Stash($name)->insert();

        return (string) $stash->uuid;
    }

    /**
     * Attach an existing Input and include its known Items; repeated attachment keeps one relationship.
     */
    public function attach(string $stash, string $input): void
    {
        $this->requireStash($stash);
        $this->configurations->read('input', $input);
        $this->database->transaction(function () use ($stash, $input): void {
            $this->lockStash($stash);
            if (StashInput::select()->where('stash_id', $stash)->where('input_id', $input)->first() === null) {
                new StashInput($stash, $input)->insert();
            }

            // Attaching an already discovered Input must expose its existing Items too.
            /**
             * @var ItemSource $source
             */
            foreach (ItemSource::select()->where('input_id', $input)->all() as $source) {
                $this->link($stash, $source->item_uri);
            }
        });
    }

    /**
     * Add the run's canonical Items to every attached Stash without copying source facts.
     */
    public function discovered(string $runId): void
    {
        $run = $this->discovery->read($runId);
        $this->database->transaction(function () use ($runId, $run): void {
            foreach ($this->acquisition->items($runId) as $attempt) {
                assert(is_array($attempt), 'Acquisition returns saved attempt records.');
                /**
                 * @var StashInput $attached
                 */
                foreach (StashInput::select()->where('input_id', $run['input_id'])->orderBy('stash_id')->all() as $attached) {
                    $this->lockStash($attached->stash_id);
                    $this->link($attached->stash_id, $attempt['item_uri']);
                }
            }
        });
    }

    /**
     * Read at most 100 members with stable URI and canonical Object identifiers.
     *
     * @return array{items: list<array{id: string, uri: string, object_id: string}>, next: ?string}
     */
    public function items(string $stash, string $after = ''): array
    {
        $this->requireStash($stash);
        $items = [];
        /**
         * @var StashItem $membership
         */
        foreach (StashItem::select()
            ->where('stash_id', $stash)
            ->whereRaw('item_id > ?', $after === '' ? '00000000-0000-0000-0000-000000000000' : $after)
            ->orderBy('item_id')
            ->limit(100)
            ->all() as $membership) {
            $item = Item::get($membership->item_id) ?? throw new RuntimeException('Stash membership lost its Item.');
            $items[] = ['id' => (string) $item->uuid, 'uri' => $item->uri, 'object_id' => $this->archive->objectIdForUri($item->uri)->toString()];
        }

        return ['items' => $items, 'next' => count($items) === 100 ? $items[99]['id'] : null];
    }

    /**
     * Freeze verified exact revisions for current membership before publication is admitted.
     */
    public function select(string $stash, string $broadcast): string
    {
        $this->requireStash($stash);

        return $this->broadcast->select($broadcast, $this->selections($stash));
    }

    /**
     * Resolve current membership once; the Broadcast coordinator freezes exact verified pins.
     * @return Generator<int, BroadcastSelection>
     */
    private function selections(string $stash): Generator
    {
        $after = '';
        do {
            $page = $this->items($stash, $after);
            foreach ($page['items'] as $item) {
                $id = $this->archive->objectIdForUri($item['uri']);
                yield new BroadcastSelection($id->toString(), $this->vault->getObject($id)->head());
            }

            $after = $page['next'];
        } while ($after !== null);
    }

    /**
     * Serialize membership inserts within the caller's transaction, including concurrent attachment.
     * Tempest's select builder has no row-lock clause in this installed version.
     */
    private function lockStash(string $stash): void
    {
        $this->database->connect()->fetchFirst(new Query('SELECT uuid FROM catalogue_stashes WHERE uuid = ? FOR UPDATE', [$stash]));
    }

    /**
     * Require persisted collection identity before reading or changing membership.
     */
    private function requireStash(string $id): Stash
    {
        $this->database->connect();

        return Stash::get($id) ?? throw new InvalidArgumentException('Stash not found.');
    }

    /**
     * Insert missing membership within the caller's transaction; never duplicate an Item.
     */
    private function link(string $stash, string $uri): void
    {
        /**
         * @var Item $item
         */
        $item = Item::select()->where('uri', $uri)->first() ?? throw new RuntimeException('Source has no Catalogue Item.');
        if (StashItem::select()->where('stash_id', $stash)->where('item_id', (string) $item->uuid)->first() === null) {
            new StashItem($stash, (string) $item->uuid)->insert();
        }
    }
}
