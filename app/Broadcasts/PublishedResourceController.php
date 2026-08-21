<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Config\StashdConfig;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use SensitiveParameter;
use Tempest\Http\ContentType;
use Tempest\Http\Response;
use Tempest\Http\Responses\NotFound;
use Tempest\Http\Responses\Ok;
use Tempest\Router\Get;

#[AllowApiClients]
final readonly class PublishedResourceController
{
    public function __construct(
        private PublishedResourceRepository $resources,
        private PublishedResourceService $publications,
        private StashdConfig $config,
    ) {
    }

    #[Get('/published/{publicationId}', without: [RequireAuthMiddleware::class])]
    public function servePublic(string $publicationId): Response
    {
        return $this->serve($publicationId, null);
    }

    #[Get('/published/{publicationId}/access/{credential}', without: [RequireAuthMiddleware::class])]
    public function serveProtected(string $publicationId, #[SensitiveParameter] string $credential): Response
    {
        return $this->serve($publicationId, $credential);
    }

    private function serve(string $publicationId, #[SensitiveParameter] ?string $credential): Response
    {
        $resource = $this->resources->find($publicationId);

        if ($resource === null || ! $this->publications->authorize($resource, $credential)) {
            return new NotFound();
        }

        try {
            $source = $this->publications->source($resource);
        } catch (\RuntimeException) {
            return new NotFound();
        }

        $response = (new Ok())
            ->addHeader(ContentType::HEADER, $source['media_type'])
            ->addHeader('Content-Length', (string) $source['size'])
            ->addHeader('Accept-Ranges', 'bytes')
            ->addHeader('X-Accel-Redirect', $this->accelPath($source['path']));

        if ($source['download_name'] !== null) {
            $response = $response->addHeader(
                'Content-Disposition',
                'inline; filename="' . addcslashes($source['download_name'], '\\"') . '"',
            );
        }

        return $response;
    }

    private function accelPath(string $path): string
    {
        $root = rtrim($this->config->mediaPath, '/') . '/';

        $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);

        return '/' . implode('/', array_map(rawurlencode(...), explode('/', $relative)));
    }
}
