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
use Tempest\Router\WithMiddleware;
use Throwable;

use function Tempest\View\view;

/**
 * Provide local read-only views of Item history and verified Asset versions.
 *
 * The middleware requires local opt-in. Downloads use verified private temporary
 * copies; browser routes never initialize the Vault or publish archival history.
 *
 * @phpstan-import-type BrowserData from BrowserPage
 */
#[WithMiddleware(RequireLocalVaultBrowser::class)]
final readonly class VaultBrowserController
{
    /**
     * Bind archival readers and display configuration for the local browser routes.
     */
    public function __construct(
        private Vault $vault,
        private ItemArchive $items,
        private AssetArchive $assets,
        private VaultConfig $config,
    ) {}

    /**
     * Enumerate readable Items and report unsupported or unreadable Objects as notices.
     *
     * An absent Vault identity produces an initialization notice; other root
     * failures return service unavailable rather than a misleading complete list.
     */
    #[Get('/')]
    public function index(): Response
    {
        $items = [];
        $notices = [];
        try {
            $root = $this->vault->open();
            foreach ($root->listObjects() as $id) {
                if (str_starts_with($id, 'urn:stashd:asset:')) {
                    try {
                        $this->assets->inspect(self::id($id, 'asset'), $root->getObject($id)->head());
                    } catch (Throwable) {
                        $notices[] = $id . ' — unreadable or incompatible Asset record.';
                    }

                    continue;
                }

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
                [
                    'kind' => 'Vault',
                    'state' => $new ? 'Vault not initialized' : 'Vault unavailable',
                    'notices' => ['Canonical Objects could not be enumerated. No complete Item list is available. Use vault:init only for a new Vault.'],
                ],
                $new ? Status::OK : Status::SERVICE_UNAVAILABLE,
            );
        }

        return $this->page([
            'kind' => 'Vault',
            'state' => 'Vault connected',
            'items' => $items,
            'notices' => $notices,
            'identity' => $this->vault->getIdentity()?->identifier->toString(),
        ]);
    }

    /**
     * Display one Item revision and verify its exact Asset pins for the page.
     *
     * An omitted version selects the current head. Missing versions return 404,
     * invalid input returns 400, and unavailable pins remain visible as errors
     * without substituting newer Asset versions.
     */
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

            return $this->page(['kind' => 'Item', 'record' => $record, 'selected' => $version, 'versions' => $object->inventory->versions, 'pins' => $pins]);
        } catch (\InvalidArgumentException) {
            return $this->error(Status::BAD_REQUEST, 'Invalid Item ID or revision');
        } catch (Throwable) {
            return $this->error(Status::NOT_FOUND, 'Item unavailable or failed verification');
        }
    }

    /**
     * Display verified evidence for an exact Asset version.
     *
     * Invalid identifiers or version syntax return 400; unavailable or failed
     * verification returns 404 without substituting another version.
     */
    #[Get('/assets/{id}/{version}')]
    public function asset(string $id, string $version): Response
    {
        try {
            self::version($version);
            $record = $this->assets->inspect(self::id(rawurldecode($id), 'asset'), $version);

            return $this->page(['kind' => 'Asset', 'record' => $record, 'selected' => $version]);
        } catch (\InvalidArgumentException) {
            return $this->error(Status::BAD_REQUEST, 'Invalid Asset ID or version');
        } catch (Throwable) {
            return $this->error(Status::NOT_FOUND, 'Asset unavailable or failed verification');
        }
    }

    /**
     * Prepare a private verified copy of one exact Asset file for transmission.
     *
     * Checks copied size and SHA-512 before constructing a download response.
     * Closes the archival input and transfers successful temporary-stream ownership
     * to VerifiedDownload. Errors become browser responses; the temporary copy
     * is closed in the general failure handler.
     */
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

    /**
     * Require the canonical lowercase Stashd URN form for the requested Object type.
     *
     * Invalid identifiers raise InvalidArgumentException before archival lookup.
     */
    private static function id(string $id, string $type): Urn
    {
        if (! preg_match('/^urn:stashd:' . $type . ':[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid archival ID.');
        }

        return Urn::fromString($id);
    }

    /**
     * Require an explicit positive OCFL version name without aliases or leading zeros.
     *
     * Invalid input raises InvalidArgumentException for the route error handlers.
     *
     * @psalm-assert string $version
     */
    private static function version(mixed $version): void
    {
        if (! is_string($version) || ! preg_match('/^v[1-9][0-9]*$/D', $version)) {
            throw new \InvalidArgumentException('Invalid version.');
        }
    }

    /**
     * Build a browser error page with the supplied HTTP status and readable message.
     */
    private function error(Status $status, string $message): Response
    {
        return $this->page(['kind' => 'Unavailable', 'error' => $message], $status);
    }

    /**
     * Render the browser view with storage configuration and caller-supplied page data.
     *
     * Disables response caching and MIME sniffing for archival browsing.
     *
     * @param BrowserData $data
     */
    private function page(array $data, Status $status = Status::OK): Response
    {
        return new GenericResponse($status, view(__DIR__ . '/browser.view.php', page: new BrowserPage($data), config: $this->config), headers: [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
