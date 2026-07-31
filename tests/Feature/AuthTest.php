<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ApiTokenRecord;
use App\Auth\ApiTokenScopes;
use App\Auth\AuthService;
use App\Auth\UserRecord;
use App\Auth\UserRepository;
use App\Config\TrustedProxyConfig;
use App\Http\ClientAddressResolver;
use InvalidArgumentException;
use PDO;
use PDOException;
use Tempest\Database\Config\SQLiteConfig;
use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Http\GenericRequest;
use Tempest\Http\Method;
use Tempest\Http\Status;

test('owner setup creates the first user', function (): void {
    $response = $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ]);

    $response->assertStatus(Status::CREATED);
    expect($response->body['user']['username'])->toBe('owner')
        ->and($response->body['setup_required'])->toBeFalse()
        ->and($this->container->get(AuthService::class)->isSetupRequired())->toBeFalse();
});

test('setup is rejected when owner already exists', function (): void {
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/auth/setup', [
        'username' => 'other',
        'password' => 'other-password',
    ]);

    $response->assertStatus(Status::CONFLICT);
    expect($response->body['error']['code'])->toBe('setup_already_completed');
});

test('separate setup connections cannot create two owners after both observe an empty database', function (): void {
    if (getenv('DB_CONNECTION') !== 'sqlite') {
        $this->markTestSkipped('This test exercises SQLite file-lock behavior.');
    }

    $path = $this->container->get(SQLiteConfig::class)->path;
    $firstConnection = new PDO('sqlite:' . $path);
    $secondConnection = new PDO('sqlite:' . $path);

    expect((int) $firstConnection->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe(0)
        ->and((int) $secondConnection->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe(0);

    $this->container->get(UserRepository::class)->createAdmin(
        username: 'owner',
        passwordHash: password_hash('secret-password', PASSWORD_DEFAULT),
    );

    $insert = $secondConnection->prepare(
        'INSERT INTO users (id, username, passwordHash, role, createdAt, updatedAt)
         VALUES (:id, :username, :passwordHash, :role, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
    );

    expect(fn () => $insert->execute([
        'id' => 'user_01J00000000000000000000000',
        'username' => 'other',
        'passwordHash' => password_hash('other-password', PASSWORD_DEFAULT),
        'role' => 'admin',
    ]))->toThrow(PDOException::class)
        ->and(UserRecord::count()->execute())->toBe(1);
});

test('the user repository reports the database single-owner constraint as setup completion', function (): void {
    $users = $this->container->get(UserRepository::class);
    $users->createAdmin('owner', password_hash('secret-password', PASSWORD_DEFAULT));

    expect(fn () => $users->createAdmin('other', password_hash('other-password', PASSWORD_DEFAULT)))
        ->toThrow(InvalidArgumentException::class, 'Admin account already exists.')
        ->and(UserRecord::count()->execute())->toBe(1);
});

test('login and session me endpoint work', function (): void {
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $login = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();

    useSessionCookieFrom($login);

    $me = $this->http->get('/api/v1/auth/me');
    $me->assertOk();
    expect($me->body['user']['username'])->toBe('owner');
});

test('login sets an httponly session cookie carrying a revocable token', function (): void {
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();

    $response->assertHasCookie(AuthService::SESSION_COOKIE, function (string $value): void {
        expect($value)->not->toBeEmpty();
    });
});

test('the session cookie issued at login authenticates protected routes', function (): void {
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $login = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();

    $this->http->get('/api/v1/auth/me')->assertStatus(Status::UNAUTHORIZED);

    useSessionCookieFrom($login);

    $me = $this->http->get('/api/v1/auth/me');
    $me->assertOk();
    expect($me->body['user']['username'])->toBe('owner');
});

test('logging in again does not revoke a prior session for the same user', function (): void {
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $firstLogin = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();
    useSessionCookieFrom($firstLogin);
    $firstCookie = $_COOKIE[AuthService::SESSION_COOKIE];

    $secondLogin = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();
    useSessionCookieFrom($secondLogin);
    $secondCookie = $_COOKIE[AuthService::SESSION_COOKIE];

    expect($secondCookie)->not->toBe($firstCookie);

    // Second login must not have kicked out the first session.
    $_COOKIE[AuthService::SESSION_COOKIE] = $firstCookie;
    $this->http->get('/api/v1/auth/me')->assertOk();

    // Both sessions genuinely coexist -- the second one still works too.
    $_COOKIE[AuthService::SESSION_COOKIE] = $secondCookie;
    $this->http->get('/api/v1/auth/me')->assertOk();
});

test('logout revokes the session token and clears the cookie', function (): void {
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $login = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();

    useSessionCookieFrom($login);

    $loggedInCookie = $_COOKIE[AuthService::SESSION_COOKIE];

    $logout = $this->http->post('/api/v1/auth/logout');
    $logout->assertOk();
    $logout->assertHasCookie(AuthService::SESSION_COOKIE, '');

    // The revoked cookie must not work even if a client kept holding onto it.
    $_COOKIE[AuthService::SESSION_COOKIE] = $loggedInCookie;
    $this->http->get('/api/v1/auth/me')->assertStatus(Status::UNAUTHORIZED);
});

test('api token authenticates protected routes', function (): void {
    $headers = $this->authHeaders();

    $tokenList = $this->http->get('/api/v1/auth/tokens', headers: $headers);
    $tokenList->assertOk();
    expect($tokenList->body['tokens'])->toHaveCount(1);

    $preflight = $this->http->post('/api/v1/stashes/preflight', [
        'sourceUri' => 'fake://channel/auth-demo',
    ], headers: $headers);

    $preflight->assertStatus(Status::CREATED);
});

test('protected routes require setup before owner exists', function (): void {
    $response = $this->http->post('/api/v1/stashes/preflight', [
        'sourceUri' => 'fake://channel/no-auth',
    ]);

    $response->assertStatus(Status::FORBIDDEN);
    expect($response->body['error']['code'])->toBe('setup_required');
});

test('protected routes require authentication after setup', function (): void {
    $setup = $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    useSessionCookieFrom($setup);

    $this->http->post('/api/v1/auth/logout')->assertOk();

    $response = $this->http->post('/api/v1/stashes/preflight', [
        'sourceUri' => 'fake://channel/no-token',
    ]);

    $response->assertStatus(Status::UNAUTHORIZED);
    expect($response->body['error']['code'])->toBe('authentication_required');
});

test('login fails before setup with setup_required', function (): void {
    $response = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ]);

    $response->assertStatus(Status::FORBIDDEN);
    expect($response->body['error']['code'])->toBe('setup_required');
});

test('invalid login credentials are rejected', function (): void {
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(Status::UNAUTHORIZED);
    expect($response->body['error']['code'])->toBe('invalid_credentials');
});

test('client addresses trust forwarded headers only from configured proxy peers', function (): void {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    $request = new GenericRequest(Method::POST, '/api/v1/auth/login', headers: ['X-Forwarded-For' => '198.51.100.42, 10.0.0.1']);

    expect(new ClientAddressResolver(new TrustedProxyConfig())->resolve($request))->toBe('10.0.0.2')
        ->and(new ClientAddressResolver(new TrustedProxyConfig(['10.0.0.2']))->resolve($request))->toBe('198.51.100.42');
});

test('login failures are throttled without changing the invalid-credential response', function (): void {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $response = $this->http->post('/api/v1/auth/login', [
            'username' => 'owner',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(Status::UNAUTHORIZED);
        expect($response->body)->toBe([
            'error' => [
                'code' => 'invalid_credentials',
                'message' => 'Invalid username or password.',
            ],
        ]);
    }

    $row = $this->container->get(Database::class)->fetchFirst(new Query('SELECT "keyHash" FROM login_attempts'));
    expect((string) $row['keyHash'])->not->toContain('owner')
        ->and((string) $row['keyHash'])->not->toContain('198.51.100.10');
});

test('successful login resets failures and expired attempts recover', function (): void {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.11';
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->http->post('/api/v1/auth/login', [
            'username' => 'owner',
            'password' => 'wrong-password',
        ])->assertStatus(Status::UNAUTHORIZED);
    }

    $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->http->post('/api/v1/auth/login', [
            'username' => 'owner',
            'password' => 'wrong-password',
        ])->assertStatus(Status::UNAUTHORIZED);
    }

    $this->container->get(Database::class)->execute(new Query("UPDATE login_attempts SET \"expiresAt\" = '1970-01-01 00:00:00'"));

    $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();
});

test('unknown usernames receive the same login failure response', function (): void {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.12';
    $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    $known = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'wrong-password',
    ]);
    $unknown = $this->http->post('/api/v1/auth/login', [
        'username' => 'nobody',
        'password' => 'wrong-password',
    ]);

    $known->assertStatus(Status::UNAUTHORIZED);
    $unknown->assertStatus(Status::UNAUTHORIZED);
    expect($unknown->body)->toBe($known->body);
});

test('api tokens can be revoked', function (): void {
    $setup = $this->http->post('/api/v1/auth/setup', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertStatus(Status::CREATED);

    useSessionCookieFrom($setup);

    $created = $this->http->post('/api/v1/auth/tokens', ['name' => 'revoke-me'])->assertStatus(Status::CREATED);
    $headers = ['Authorization' => 'Bearer ' . $created->body['token']];

    $this->http->post('/api/v1/auth/logout')->assertOk();

    $this->http->get('/api/v1/auth/me', headers: $headers)->assertOk();

    $this->http->delete('/api/v1/auth/tokens/' . $created->body['id'], headers: $headers)->assertOk();

    $this->http->get('/api/v1/auth/me', headers: $headers)->assertStatus(Status::UNAUTHORIZED);
});

test('api token scopes are stored as a typed value object', function (): void {
    $headers = $this->authHeaders();

    $created = $this->http->post('/api/v1/auth/tokens', [
        'name' => 'scoped-token',
        'scopes' => ['media:read', ' media:read ', 'broadcast:write'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $record = ApiTokenRecord::select()
        ->where('id', $created->body['id'])
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->scopes)->toBeInstanceOf(ApiTokenScopes::class)
        ->and($record->scopes?->toArray())->toBe(['media:read', 'broadcast:write']);

    $row = $this->container->get(Database::class)->fetchFirst(new Query(
        'SELECT scopes FROM api_tokens WHERE id = ?',
        bindings: [$created->body['id']],
    ));
    $storedScopes = json_decode((string) $row['scopes'], true, flags: JSON_THROW_ON_ERROR);

    expect($storedScopes)->toBe([
        'type' => 'api_token_scopes',
        'data' => [
            'values' => ['media:read', 'broadcast:write'],
        ],
    ]);

    $tokenList = $this->http->get('/api/v1/auth/tokens', headers: $headers);

    $scopedToken = array_values(array_filter(
        $tokenList->body['tokens'],
        static fn (array $token): bool => $token['id'] === $created->body['id'],
    ))[0] ?? null;

    expect($scopedToken)->not->toBeNull()
        ->and($scopedToken['scopes'])->toBe(['media:read', 'broadcast:write']);
});

test('api token creation rejects unknown, non-string, and non-array scopes', function (): void {
    $headers = $this->authHeaders();

    foreach ([['unknown:scope'], ['media:read', 123], 'media:read'] as $scopes) {
        $response = $this->http->post('/api/v1/auth/tokens', [
            'name' => 'invalid-scope',
            'scopes' => $scopes,
        ], headers: $headers);

        $response->assertStatus(Status::BAD_REQUEST);
        expect($response->body['error']['code'])->toBe('validation_error');
    }
});

test('a read-scoped token can read media but cannot mutate stashes', function (): void {
    $headers = $this->scopedAuthHeaders(['media:read']);

    $this->http->get('/api/v1/items', headers: $headers)->assertOk();

    $denied = $this->http->post('/api/v1/stashes', ['name' => 'Denied'], headers: $headers)
        ->assertStatus(Status::FORBIDDEN);

    expect($denied->body)->toBe([
        'error' => [
            'code' => 'scope_required',
            'message' => 'This API token does not grant access to this operation.',
        ],
    ]);
});

test('a stash-write token can mutate stashes but cannot read unrelated media', function (): void {
    $headers = $this->scopedAuthHeaders(['stash:write']);

    $this->http->post('/api/v1/stashes', ['name' => 'Scoped Stash'], headers: $headers)
        ->assertStatus(Status::CREATED);
    $this->http->get('/api/v1/items', headers: $headers)->assertStatus(Status::FORBIDDEN);
});

test('a media-server-write token can mutate media servers but cannot administer tokens', function (): void {
    $headers = $this->scopedAuthHeaders(['media-server:write']);

    $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Scoped Jellyfin',
        'base_uri' => 'http://jellyfin.test',
        'token' => 'fixture-secret',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->http->get('/api/v1/auth/tokens', headers: $headers)->assertStatus(Status::FORBIDDEN);
});

test('a token-management grant can administer and revoke itself', function (): void {
    $created = $this->http->post('/api/v1/auth/tokens', [
        'name' => 'token-manager',
        'scopes' => ['tokens:manage'],
    ], headers: $this->authHeaders())->assertStatus(Status::CREATED);
    $headers = ['Authorization' => 'Bearer ' . $created->body['token']];

    $this->http->get('/api/v1/auth/tokens', headers: $headers)->assertOk();
    $this->http->delete('/api/v1/auth/tokens/' . $created->body['id'], headers: $headers)->assertOk();
    $this->http->get('/api/v1/auth/tokens', headers: $headers)->assertStatus(Status::UNAUTHORIZED);
});

test('an expired scoped token remains unauthenticated', function (): void {
    $auth = $this->container->get(AuthService::class);
    $user = $this->container->get(UserRepository::class)->findByUsername('owner');

    if ($user === null) {
        $this->authHeaders();
        $user = $this->container->get(UserRepository::class)->findByUsername('owner');
    }

    $created = $auth->createApiToken(
        $user,
        'expired-reader',
        ['media:read'],
        DateTime::now(Timezone::UTC)->minusSeconds(1),
    );

    $this->http->get('/api/v1/items', headers: ['Authorization' => 'Bearer ' . $created['token']])
        ->assertStatus(Status::UNAUTHORIZED);
});

test('legacy empty-scope tokens retain full owner access', function (): void {
    $headers = $this->scopedAuthHeaders([]);

    $this->http->post('/api/v1/stashes', ['name' => 'Legacy Full Access'], headers: $headers)
        ->assertStatus(Status::CREATED);
    $this->http->get('/api/v1/auth/tokens', headers: $headers)->assertOk();
});
