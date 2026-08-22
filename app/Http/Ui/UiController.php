<?php

declare(strict_types=1);

namespace App\Http\Ui;

use App\Auth\AuthService;
use App\Http\Middleware\RequireAuthMiddleware;
use Tempest\Router\Get;
use Tempest\View\View;

use function Tempest\View\view;

/**
 * Serves the server-rendered HTML shells for the dashboard.
 *
 * RequireAuthMiddleware is auto-applied to every route by Tempest's
 * HttpMiddlewareDiscovery (any HttpMiddleware implementation is global, not
 * opt-in), so these public shells must each explicitly opt out via `without:`
 * — the same mechanism generated-resource responses use.
 * The shells carry no data; they hydrate client-side against the
 * bearer-cookie-authenticated /api/v1. An unauthenticated visit renders the
 * frame, then the client auth gate redirects to /login when the session
 * cookie is missing or invalid.
 */
final readonly class UiController
{
    public function __construct(
        private AuthService $auth,
    ) {}

    #[Get('/login', without: [RequireAuthMiddleware::class])]
    public function login(): View
    {
        return view(__DIR__ . '/Views/login.view.php', setupRequired: $this->auth->isSetupRequired());
    }

    #[Get('/', without: [RequireAuthMiddleware::class])]
    public function dashboard(): View
    {
        return view(__DIR__ . '/Views/dashboard.view.php');
    }

    #[Get('/stashes', without: [RequireAuthMiddleware::class])]
    public function stashes(): View
    {
        return view(__DIR__ . '/Views/stashes.view.php');
    }

    #[Get('/stashes/new', without: [RequireAuthMiddleware::class])]
    public function newStash(): View
    {
        return view(__DIR__ . '/Views/create-stash.view.php');
    }

    #[Get('/stashes/{id}', without: [RequireAuthMiddleware::class])]
    public function stashDetail(string $id): View
    {
        return view(__DIR__ . '/Views/stash-detail.view.php', id: $id);
    }

    #[Get('/vault', without: [RequireAuthMiddleware::class])]
    public function vault(): View
    {
        return view(__DIR__ . '/Views/vault.view.php');
    }

    #[Get('/vault/{id}', without: [RequireAuthMiddleware::class])]
    public function vaultDetail(string $id): View
    {
        return view(__DIR__ . '/Views/vault-detail.view.php', id: $id);
    }

    #[Get('/activity', without: [RequireAuthMiddleware::class])]
    public function activity(): View
    {
        return view(__DIR__ . '/Views/activity.view.php');
    }

    #[Get('/settings', without: [RequireAuthMiddleware::class])]
    public function settings(): View
    {
        return view(__DIR__ . '/Views/settings.view.php');
    }
}
