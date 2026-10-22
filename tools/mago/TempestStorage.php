<?php

namespace Tempest\Storage;

use League\Flysystem\StorageAttributes;

/**
 * Retain the Flysystem element type omitted by Tempest's listing declarations.
 *
 * Analysis metadata only: GenericStorage::list wraps FilesystemReader::listContents,
 * whose declared element type is StorageAttributes. Never autoload this file.
 */
interface Storage
{
    /**
     * Enumerate the file and directory attributes supplied by the storage provider.
     *
     * @return DirectoryListing<StorageAttributes>
     */
    public function list(string $location = '', bool $deep = false): DirectoryListing;
}

/**
 * Preserve the same listing type on the concrete Tempest implementation.
 */
final class GenericStorage
{
    /**
     * Wrap Flysystem attributes without changing their element type.
     *
     * @return DirectoryListing<StorageAttributes>
     */
    public function list(string $location = '', bool $deep = false): DirectoryListing {}
}
