# Stashd native plugin SDK

This package is the author-facing PHP surface for native Stashd plugins. It
contains only contract DTOs, lifecycle interfaces, capability interfaces, and
wire mapping. RPC framing, process supervision, bubblewrap, package activation,
and Stashd application services are runtime concerns outside this package.

The package is independently versionable and requires PHP 8.5 or newer. Its
`files` autoload is deliberate: the contract groups contain multiple small
public value types and keep the package usable without a framework or service
container.
