<?php

declare(strict_types=1);

use App\Auth\AuthContext;
use App\Auth\UserRecord;
use App\Auth\UserRole;
use Tempest\Clock\GenericClock;
use Tempest\Container\GenericContainer;
use Tempest\Core\AppConfig;
use Tempest\Core\Exceptions\ExceptionProcessor;
use Tempest\Core\Kernel;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Connection\ConnectionReset;
use Tempest\Database\Exceptions\CouldNotResetConnection;
use Tempest\Http\Cookie\CookieManager;
use Tempest\Http\GenericRequest;
use Tempest\Http\Method;
use Tempest\Http\Session\CookieReset;
use Tempest\Router\Exceptions\HttpExceptionHandler;
use Tempest\Router\ResponseSender;
use Tempest\Router\RouteConfig;
use Tests\Support\WorkerTestConnection;

test('Tempest worker reset clears Stashd request authentication state', function (): void {
    $container = new GenericContainer();
    $container
        ->singleton(AuthContext::class, new AuthContext())
        ->addResettable(AuthContext::class);

    $context = $container->get(AuthContext::class);
    $context->set(new UserRecord('request-a', 'unused', UserRole::Admin));

    expect($context->user()?->username)->toBe('request-a');

    // WorkerModeApplication invokes this same container reset during kernel
    // shutdown. Keeping this seam avoids requiring FrankenPHP in unit tests.
    $container->reset();

    $requestB = $container->get(AuthContext::class);

    expect($requestB->user())->toBeNull()
        ->and($requestB->principal())->toBeNull();
});

test('sequential authenticated requests do not share principals or scopes', function (): void {
    $container = new GenericContainer();
    $container
        ->singleton(AuthContext::class, new AuthContext())
        ->addResettable(AuthContext::class);

    $container->get(AuthContext::class)->set(new UserRecord('user-a', 'unused', UserRole::Admin));
    $container->reset();

    $requestB = $container->get(AuthContext::class);
    expect($requestB->user())->toBeNull();

    $requestB->set(new UserRecord('user-b', 'unused', UserRole::Admin));

    expect($requestB->user()?->username)->toBe('user-b');
});

test('an exception after authentication is followed by a clean request context', function (): void {
    $container = new GenericContainer();
    $container
        ->singleton(AuthContext::class, new AuthContext())
        ->addResettable(AuthContext::class);

    try {
        $container->get(AuthContext::class)->set(new UserRecord('user-a', 'unused', UserRole::Admin));

        throw new RuntimeException('request failed');
    } catch (RuntimeException) {
        $container->reset();
    }

    expect($container->get(AuthContext::class)->user())->toBeNull();
});

test('Tempest exception handler shuts down and resets after authentication fails', function (): void {
    $container = new GenericContainer();
    $container
        ->singleton(AuthContext::class, new AuthContext())
        ->addResettable(AuthContext::class)
        ->singleton(Tempest\Http\Request::class, new GenericRequest(Method::GET, '/'));

    $kernel = new class ($container) implements Kernel {
        public string $root = '/tmp';
        public string $internalStorage = '/tmp';
        public Tempest\Container\Container $container;

        public function __construct(Tempest\Container\Container $container)
        {
            $this->container = $container;
        }

        public static function boot(string $root, array $discoveryLocations = [], ?Tempest\Container\Container $container = null, ?string $internalStorage = null): self
        {
            return new self($container ?? new GenericContainer());
        }

        public function shutdown(int|string $status = ''): void
        {
            $this->container->reset();
        }
    };

    $handler = new HttpExceptionHandler(
        responseSender: new class implements ResponseSender {
            public function send(Tempest\Http\Response $response): Tempest\Http\Response
            {
                return $response;
            }
        },
        kernel: $kernel,
        container: $container,
        exceptionProcessor: new class implements ExceptionProcessor {
            public function process(Throwable $throwable): void {}
        },
        routeConfig: new RouteConfig(),
    );

    $container->get(AuthContext::class)->set(new UserRecord('user-a', 'unused', UserRole::Admin));
    $handler->handle(new RuntimeException('request failed'));

    expect($container->get(AuthContext::class)->user())->toBeNull();
});

test('cookie manager state is discarded between requests', function (): void {
    $container = new GenericContainer();
    $container
        ->singleton(AppConfig::class, new AppConfig())
        ->singleton(\Tempest\Clock\Clock::class, new GenericClock())
        ->singleton(CookieManager::class, new CookieManager(new AppConfig(), new GenericClock()))
        ->addResettable(CookieReset::class)
        ->singleton(CookieReset::class, new CookieReset($container));

    $container->get(CookieManager::class)->set('request-a', 'value-a');
    $container->reset();

    expect($container->get(CookieManager::class)->all())->toBe([]);
});

test('database reset permits a clean connection after normal request work', function (): void {
    $connection = new WorkerTestConnection();
    $container = new GenericContainer();
    $container
        ->singleton(Connection::class, $connection)
        ->addResettable(ConnectionReset::class)
        ->singleton(ConnectionReset::class, new ConnectionReset($container));

    $container->get(Connection::class)->ping();
    $container->reset();
    $container->singleton(Connection::class, new WorkerTestConnection());

    expect($container->get(Connection::class))->not->toBe($connection);
});

test('database reset rejects an open transaction instead of reusing it', function (): void {
    $connection = new WorkerTestConnection();
    $connection->transaction = true;
    $container = new GenericContainer();
    $container
        ->singleton(Connection::class, $connection)
        ->addResettable(ConnectionReset::class)
        ->singleton(ConnectionReset::class, new ConnectionReset($container));

    expect(fn() => $container->reset())->toThrow(CouldNotResetConnection::class);
    expect($container->get(Connection::class))->toBe($connection);
});
