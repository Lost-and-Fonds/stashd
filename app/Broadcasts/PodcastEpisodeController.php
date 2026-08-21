<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Broadcasts\Podcasts\PodcastAssetSelector;
use App\Broadcasts\Podcasts\PodcastChapterJsonBuilder;
use App\Broadcasts\Podcasts\PodcastMediaKind;
use App\Broadcasts\Podcasts\PodcastTokenService;
use App\Config\StashdConfig;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use SensitiveParameter;
use Tempest\Http\ContentType;
use Tempest\Http\Response;
use Tempest\Http\Responses\NotFound;
use Tempest\Http\Responses\Ok;
use Tempest\Router\Get;

/**
 * Public, unauthenticated podcast episode media surface.
 *
 * Mirrors {@see PodcastFeedController}: the broadcast and item path tokens
 * are the only credential, so an unknown, mismatched, or revoked token must
 * be indistinguishable from an episode that never existed. This controller
 * serves only the selected Vault or broadcast-local remux asset for the
 * matched broadcast item — it never resolves a filesystem path from
 * request input and never transcodes, remuxes, or extracts media.
 *
 * The router binds one path segment to one parameter, so the final
 * `episode.{ext}` segment is captured whole (e.g. `episode.mp3`) and split
 * here rather than in the route pattern.
 *
 * No episode bytes ever pass through PHP: this returns a bodyless response
 * carrying `X-Accel-Redirect`, and Caddy's `intercept` block (`docker/Caddyfile`)
 * rewrites the request to that path and serves it via `file_server`, which
 * natively handles Range/HEAD/If-Range.
 */
#[AllowApiClients]
final readonly class PodcastEpisodeController
{
    private const string EPISODE_FILENAME_PREFIX = 'episode.';

    public function __construct(
        private PodcastTokenService $tokens,
        private PodcastAssetSelector $assets,
        private PodcastChapterJsonBuilder $chapters,
        private StashdConfig $config,
    ) {
    }

    #[Get('/b/{broadcastToken}/items/{itemToken}/{episodeFile}', without: [RequireAuthMiddleware::class])]
    public function episode(
        #[SensitiveParameter] string $broadcastToken,
        #[SensitiveParameter] string $itemToken,
        string $episodeFile,
    ): Response {
        $ext = $this->extensionFromEpisodeFile($episodeFile);

        if ($ext === null) {
            return $this->notRevealed();
        }

        $broadcast = $this->tokens->findPodcastBroadcastByFeedToken($broadcastToken);

        if ($broadcast === null) {
            return $this->notRevealed();
        }

        $item = $this->tokens->findBroadcastItemByEpisodeToken($broadcast, $itemToken);

        if ($item === null) {
            return $this->notRevealed();
        }

        $kind = PodcastMediaKind::forBroadcast($broadcast);
        $selection = match ($kind) {
            PodcastMediaKind::Audio => $this->assets->audioAsset($item->mediaItemId),
            PodcastMediaKind::Video => $this->assets->videoAsset($item->mediaItemId),
        };

        // `{ext}` is a presentation hint only; the selected asset's own
        // extension is the source of truth, never the request path.
        if ($selection === null || $selection->extension !== $ext) {
            return $this->notRevealed();
        }

        $path = $selection->asset->path;

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return $this->notRevealed();
        }

        $accelPath = $this->accelRedirectPath($path);

        if ($accelPath === null) {
            return $this->notRevealed();
        }

        return (new Ok())
            ->addHeader(ContentType::HEADER, $selection->mimeType)
            ->addHeader('X-Accel-Redirect', $accelPath);
    }

    #[Get('/b/{broadcastToken}/items/{itemToken}/chapters.json', without: [RequireAuthMiddleware::class])]
    public function chapterJson(#[SensitiveParameter] string $broadcastToken, #[SensitiveParameter] string $itemToken): Response
    {
        $broadcast = $this->tokens->findPodcastBroadcastByFeedToken($broadcastToken);
        $item = $broadcast === null ? null : $this->tokens->findBroadcastItemByEpisodeToken($broadcast, $itemToken);

        if ($item === null) {
            return $this->notRevealed();
        }

        return (new Ok($this->chapters->build($item->mediaItemId)))
            ->addHeader(ContentType::HEADER, 'application/json; charset=utf-8');
    }

    private function extensionFromEpisodeFile(string $episodeFile): ?string
    {
        if (! str_starts_with($episodeFile, self::EPISODE_FILENAME_PREFIX)) {
            return null;
        }

        $ext = substr($episodeFile, strlen(self::EPISODE_FILENAME_PREFIX));

        return $ext === '' ? null : $ext;
    }

    /**
     * Caddy's intercept block (`docker/Caddyfile`) roots the rewritten
     * request at `StashdConfig::mediaPath`, so this must be a URL path
     * relative to a managed media directory — never an absolute filesystem path, which
     * would leak host layout to the client if the strip-header step were
     * ever misconfigured. Rejects (rather than guesses) any asset path
     * outside the Vault/broadcast roots.
     */
    private function accelRedirectPath(string $absolutePath): ?string
    {
        $root = rtrim($this->config->vaultPath(), '/') . '/';
        $prefix = 'vault';

        if (! str_starts_with($absolutePath, $root)) {
            $root = rtrim($this->config->broadcastsPath(), '/') . '/';
            $prefix = 'broadcasts';

            if (! str_starts_with($absolutePath, $root)) {
                return null;
            }
        }

        $relative = substr($absolutePath, strlen($root));
        $segments = array_map(rawurlencode(...), explode('/', $relative));

        return '/' . $prefix . '/' . implode('/', $segments);
    }

    /**
     * A single non-revealing response for every failure mode (unknown
     * broadcast/item token, cross-broadcast item token, non-podcast
     * broadcast, unavailable asset, extension mismatch, unreadable file). It
     * carries no broadcast identity, item identity, token, or filesystem
     * path.
     */
    private function notRevealed(): NotFound
    {
        return new NotFound();
    }
}
