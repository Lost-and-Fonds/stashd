<?php

declare(strict_types=1);

namespace App\Auth;

use Tempest\Http\Method;
use Tempest\Http\Request;

final readonly class ApiScopePolicy
{
    public function requiredFor(Request $request): ?ApiScope
    {
        $path = parse_url($request->path, PHP_URL_PATH) ?: $request->path;
        $read = in_array($request->method, [Method::GET, Method::HEAD], true);

        return match (true) {
            $path === '/api/v1/auth/me' => ApiScope::ProfileRead,
            $path === '/api/v1/auth/logout', str_starts_with($path, '/api/v1/auth/tokens') => ApiScope::TokensManage,
            $path === '/api/v1/system/health' => ApiScope::SystemRead,
            str_starts_with($path, '/api/v1/jobs'), $read && str_starts_with($path, '/api/v1/commands') => ApiScope::JobsRead,
            str_starts_with($path, '/api/v1/commands') => ApiScope::CommandsCreate,
            $path === '/api/v1/events/subscription', str_starts_with($path, '/api/v1/activity') => ApiScope::ActivityRead,
            str_starts_with($path, '/api/v1/items') => ApiScope::MediaRead,
            str_starts_with($path, '/api/v1/connections') => $read ? ApiScope::ConnectionsRead : ApiScope::ConnectionsWrite,
            str_starts_with($path, '/api/v1/broadcast'), str_contains($path, '/broadcasts') => $read ? ApiScope::BroadcastRead : ApiScope::BroadcastWrite,
            str_starts_with($path, '/api/v1/stashes') => $read ? ApiScope::StashRead : ApiScope::StashWrite,
            default => null,
        };
    }
}
