<?php

namespace App\RecordsAndArchives;

use League\Uri\Exceptions\SyntaxError;
use League\Uri\Uri;
use League\Uri\Urn;
use Symfony\Component\Uid\Uuid;

/**
 * Define Item identity from the exact validated canonical URI bytes.
 *
 * Parsing validates syntax without normalizing case, escapes or URI parts.
 * This policy is shared by Item and Asset archives and performs no writes.
 */
final readonly class ItemIdentity
{
    /**
     * Use the Vault URN formatter without requiring an initialized storage root.
     */
    public function __construct(
        private Vault $vault,
    ) {}

    /**
     * Return the deterministic Item URN for a validated exact URI.
     *
     * No archived Item needs to exist; invalid URIs raise InvalidItemRevision.
     */
    public function objectIdForUri(string $uri): Urn
    {
        return $this->vault->urnFromUuid(IdentifierType::Item, $this->uuidForUri($uri));
    }

    /**
     * Validate an absolute ASCII URI and derive its namespaced UUID v5.
     *
     * Hashes the original bytes rather than the parser serialization, preserving
     * case and encoding distinctions. Invalid syntax raises InvalidItemRevision.
     */
    public function uuidForUri(string $uri): Uuid
    {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:.+$/D', $uri) || preg_match('/[^a-zA-Z0-9._~:\/?#\[\]@!$&\x27()*+,;=%-]/', $uri) || preg_match('/%(?![a-fA-F0-9]{2})/', $uri)) {
            throw new InvalidItemRevision('Canonical Item identity must be an absolute, ASCII URI with valid percent escapes.');
        }

        try {
            Uri::new($uri);
        } catch (SyntaxError $exception) {
            throw new InvalidItemRevision('Canonical Item URI is invalid.', previous: $exception);
        }

        // Parse only for validation: never hash the parser's normalized serialization.
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'stashd:item:exact-uri-v1:' . $uri);
    }
}
