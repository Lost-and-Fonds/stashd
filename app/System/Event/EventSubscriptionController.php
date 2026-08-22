<?php

declare(strict_types=1);

namespace App\System\Event;

use App\Auth\AuthService;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use Tempest\Core\AppConfig;
use Tempest\Http\Cookie\Cookie;
use Tempest\Http\Cookie\CookieManager;
use Tempest\Http\Cookie\SameSite;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Router\Get;
use Tempest\Router\WithMiddleware;
use Tempest\Support\Str;

/**
 * Mints a subscriber JWT and sets it as the `mercureAuthorization` cookie,
 * scoped to the hub's own path so the browser only ever sends it to
 * /.well-known/mercure. The frontend calls this before opening its shared
 * EventSource and again on reconnect errors (the JWT is short-lived).
 */
#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class EventSubscriptionController
{
    public function __construct(
        private AuthService $auth,
        private AppConfig $appConfig,
        private CookieManager $cookies,
    ) {}

    #[Get('/api/v1/events/subscription')]
    public function __invoke(Request $request): Json
    {
        $this->cookies->add(new Cookie(
            key: 'mercureAuthorization',
            value: $this->auth->issueMercureSubscriberToken(),
            expiresAt: time() + AuthService::MERCURE_SUBSCRIBER_TOKEN_TTL_SECONDS,
            path: '/.well-known/mercure',
            secure: $this->requestIsSecure($request),
            httpOnly: true,
            sameSite: SameSite::STRICT,
        ));

        return new Json(['ok' => true]);
    }

    /**
     * docker/Caddyfile never terminates TLS itself (a bare HTTP port) -- any
     * deployment reachable over HTTPS gets there through an external reverse
     * proxy/tunnel (e.g. NetBird, Cloudflare Tunnel) that terminates TLS and
     * forwards internally as plain HTTP. A single static `baseUri` can't
     * reflect that per request: a NAS reachable at both a public HTTPS
     * domain and a plain-HTTP LAN IP would otherwise always get the same
     * fixed answer, wrongly marking this cookie Secure (and unsendable) on
     * whichever origin doesn't match. Trust `X-Forwarded-Proto` when a proxy
     * sets it (first value, in case of multiple hops); fall back to
     * `baseUri` only when nothing is reached through a proxy at all.
     */
    private function requestIsSecure(Request $request): bool
    {
        $forwardedProto = $request->headers->get('X-Forwarded-Proto');

        if ($forwardedProto !== null) {
            return strtolower(trim(explode(',', $forwardedProto)[0])) === 'https';
        }

        return Str\starts_with($this->appConfig->baseUri, 'https');
    }
}
