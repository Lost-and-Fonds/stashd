# Stashd native plugin SDK

This package is the author-facing PHP surface for native Stashd plugins. It
contains only contract DTOs, lifecycle interfaces, capability interfaces, native
RPC bootstrap, and wire mapping. The bootstrap keeps framing and process
mechanics out of plugin code; supervision, bubblewrap, package activation, and
Stashd application services remain runtime concerns outside this package.

The package is independently versionable and requires PHP 8.5 or newer. Its
PSR-4 autoloading keeps the package usable without a framework or service
container. The native runner mounts this package read-only as `/sdk` for an
invocation; an entrypoint only needs to require `/sdk/bootstrap.php`.
