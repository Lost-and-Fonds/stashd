<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use Tempest\Http\GenericResponse;
use Tempest\Http\Response;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class StashCollectionExportController
{
    public function __construct(private StashCollectionExportService $exports) {}

    #[Get('/api/v1/stash-collection-exporters')]
    public function index(): \Tempest\Http\Responses\Json
    {
        $entries = $this->exports->entries();

        return new \Tempest\Http\Responses\Json([
            'exporters' => array_map(
                static fn(StashCollectionExporter $exporter): array => [
                    'key' => $exporter->key(),
                    'label' => $exporter->label(),
                    'available' => $entries !== [],
                ],
                $this->exports->exporters(),
            ),
        ]);
    }

    #[Get('/api/v1/stash-collection-exports/{key}')]
    public function export(string $key): Response
    {
        $exporter = $this->exports->exporter($key);

        if ($exporter === null) {
            return new GenericResponse(Status::NOT_FOUND);
        }

        $file = $exporter->export($this->exports->entries());

        return new GenericResponse(Status::OK, $file->contents, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => 'attachment; filename="' . addcslashes($file->filename, '\\\"') . '"',
            'Content-Length' => (string) strlen($file->contents),
        ]);
    }
}
