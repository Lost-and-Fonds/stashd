<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use App\Administration\VaultConfig;
use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemArchive;
use App\RecordsAndArchives\Vault;
use League\Uri\Urn;
use Tempest\Http\GenericResponse;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Throwable;

use function Tempest\View\view;

final readonly class VaultBrowserController
{
    public function __construct(
        private Vault $vault,
        private ItemArchive $items,
        private AssetArchive $assets,
        private VaultConfig $config,
    ) {}

    #[Get('/')]
    public function index(): Response
    {
        $items = [];
        $notices = [];
        try {
            $root = $this->vault->open();
            foreach ($root->listObjects() as $id) {
                if (! str_starts_with($id, 'urn:stashd:item:')) {
                    $notices[] = $id . ' — not an Item; omitted from the Item list.';
                    continue;
                }
                try {
                    $version = $root->getObject($id)->head();
                    $record = $this->items->read(self::id($id, 'item'), $version);
                    $items[] = [...$record, 'ocfl_version' => $version];
                } catch (Throwable) {
                    $notices[] = $id . ' — unreadable or incompatible Item record.';
                }
            }
        } catch (Throwable $error) {
            $new = $error::class === \Exception::class && $error->getMessage() === 'Vault identity does not exist';
            return $this->page(
                'Vault',
                [
                    'state' => $new ? 'Vault not initialized' : 'Vault unavailable',
                    'notices' => ['Canonical Objects could not be enumerated. No complete Item list is available. Use vault:init only for a new Vault.'],
                ],
                $new ? Status::OK : Status::SERVICE_UNAVAILABLE,
            );
        }
        return $this->page('Vault', ['state' => 'Vault connected', 'items' => $items, 'notices' => $notices, 'identity' => $this->vault->getIdentity()?->identifier->toString()]);
    }

    #[Get('/items/{id}')]
    public function item(string $id, Request $request): Response
    {
        try {
            $id = rawurldecode($id);
            $urn = self::id($id, 'item');
            $object = $this->vault->open()->getObject($id);
            $version = $request->query['version'] ?? $object->head();
            self::version($version);
            if (! isset($object->inventory->versions[$version])) {
                return $this->error(Status::NOT_FOUND, 'Item revision not found');
            }
            $record = $this->items->read($urn, $version);
            $pins = [];
            /** @var list<array{object_id: string, version: string}> $references */
            $references = $record['pins'];
            foreach ($references as $pin) {
                try {
                    $asset = $this->assets->inspect(self::id($pin['object_id'], 'asset'), $pin['version']);
                    if ($asset['item_object_id'] !== $id || $asset['item_uri'] !== $record['uri']) {
                        throw new \RuntimeException('Asset ownership differs.');
                    }
                    $pins[] = [...$pin, 'asset' => $asset];
                } catch (Throwable) {
                    $pins[] = [...$pin, 'error' => 'Pinned Asset unavailable or failed verification.'];
                }
            }
            return $this->page('Item', ['record' => $record, 'selected' => $version, 'versions' => $object->inventory->versions, 'pins' => $pins]);
        } catch (\InvalidArgumentException) {
            return $this->error(Status::BAD_REQUEST, 'Invalid Item ID or revision');
        } catch (Throwable) {
            return $this->error(Status::NOT_FOUND, 'Item unavailable or failed verification');
        }
    }

    #[Get('/assets/{id}/{version}')]
    public function asset(string $id, string $version): Response
    {
        try {
            self::version($version);
            $record = $this->assets->inspect(self::id(rawurldecode($id), 'asset'), $version);
            return $this->page('Asset', ['record' => $record, 'selected' => $version]);
        } catch (\InvalidArgumentException) {
            return $this->error(Status::BAD_REQUEST, 'Invalid Asset ID or version');
        } catch (Throwable) {
            return $this->error(Status::NOT_FOUND, 'Asset unavailable or failed verification');
        }
    }

    #[Get('/assets/{id}/{version}/download')]
    public function download(string $id, string $version, Request $request): Response
    {
        $input = null;
        $copy = null;
        try {
            self::version($version);
            $urn = self::id(rawurldecode($id), 'asset');
            $path = $request->query['path'] ?? null;
            if (! is_string($path)) {
                throw new \InvalidArgumentException('A logical path is required.');
            }
            $record = $this->assets->inspect($urn, $version);
            $input = $this->assets->readStream($urn, $version, $path);
            $copy = tmpfile();
            if ($copy === false || stream_copy_to_stream($input, $copy) === false) {
                throw new \RuntimeException('Could not prepare download.');
            }
            rewind($copy);
            $hash = hash_init('sha512');
            $size = hash_update_stream($hash, $copy);
            $evidence = $record['files'][$path];
            if (! feof($copy) || $size !== $evidence['size'] || hash_final($hash) !== $evidence['digest']) {
                throw new \RuntimeException('Download copy failed verification.');
            }
            return new VerifiedDownload($copy, basename($path), $size);
        } catch (\InvalidArgumentException) {
            return $this->error(Status::BAD_REQUEST, 'Invalid download request');
        } catch (Throwable) {
            if (is_resource($copy)) {
                fclose($copy);
            }
            return $this->error(Status::NOT_FOUND, 'Download unavailable or failed verification');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    private static function id(string $id, string $type): Urn
    {
        if (! preg_match('/^urn:stashd:' . $type . ':[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid archival ID.');
        }
        return Urn::fromString($id);
    }

    private static function version(mixed $version): void
    {
        if (! is_string($version) || ! preg_match('/^v[1-9][0-9]*$/D', $version)) {
            throw new \InvalidArgumentException('Invalid version.');
        }
    }

    private function error(Status $status, string $message): Response
    {
        return $this->page('Unavailable', ['error' => $message], $status);
    }

    private function page(string $title, array $data, Status $status = Status::OK): Response
    {
        return new GenericResponse($status, view(__DIR__ . '/browser.view.php', title: $title, data: $data, config: $this->config), headers: [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
