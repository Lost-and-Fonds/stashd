<?php

namespace App\Integration;

use InvalidArgumentException;
use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;
use Tempest\Validation\Validator;
use Throwable;

use function Tempest\Mapper\map;

/**
 * Retain traversal positions and known coverage gaps across acknowledged process restarts.
 */
#[Strict]
final class FilesystemCursor
{
    #[Rules\IsIn([1])]
    public int $version;
    /** @var list<\App\Integration\FilesystemFrame> */
    #[Rules\IsArrayList]
    public array $frames;
    #[IsProtocolValue('nonnegative-integer')]
    public int $skipped;
    #[IsProtocolValue('string', orNull: true)]
    public ?string $first;
    #[IsProtocolValue('boolean')]
    public bool $retryable;

    /**
     * Deserialize a self-contained checkpoint through Tempest's strict typed mapper.
     *
     * Validate scalars before coercing casters; Strict mapping distinguishes omitted
     * nullable fields from explicit null. The shared filesystem policy owns paths.
     */
    public static function decode(?string $value, Validator $validator, FilesystemRoots $roots): self
    {
        try {
            $values = $value === null
                ? ['version' => 1, 'frames' => [['path' => '', 'position' => 0]], 'skipped' => 0, 'first' => null, 'retryable' => false]
                : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($values) || $validator->validateValue($values, new IsDiscoveryRecord()) !== [] || $validator->validateValuesForClass(self::class, $values) !== []) {
                throw new InvalidArgumentException('Invalid Filesystem discovery continuation.');
            }
            /** @var list<mixed> $frames */
            $frames = $values['frames'];
            foreach ($frames as $frame) {
                if (
                    ! is_array($frame)
                    || $validator->validateValue($frame, new IsDiscoveryRecord()) !== []
                    || $validator->validateValuesForClass(FilesystemFrame::class, $frame) !== []
                ) {
                    throw new InvalidArgumentException('Invalid Filesystem discovery frame.');
                }
                /** @var FilesystemFrame $frame */
                $frame = map($frame)->to(FilesystemFrame::class);
                $roots->relative($frame->path, allowEmpty: true);
            }
            /** @var self $cursor */
            $cursor = map($values)->to(self::class);
            if ($cursor->skipped > 0 && $cursor->first === null) {
                throw new InvalidArgumentException('A known discovery gap requires its diagnostic.');
            }
            return $cursor;
        } catch (Throwable $error) {
            throw new InvalidArgumentException('Invalid Filesystem discovery continuation: ' . $error->getMessage(), previous: $error);
        }
    }
}
