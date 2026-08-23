<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Rpc;

use JsonException;

final class FrameCodec
{
    public const MAX_FRAME_BYTES = 65_536;

    /** @param resource $stream */
    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $message
     */
    public static function write($stream, array $message): void
    {
        $json = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $length = strlen($json);
        if ($length > self::MAX_FRAME_BYTES) {
            throw new FrameProtocolError('frame exceeds maximum size');
        }

        self::writeAll($stream, pack('N', $length) . $json);
    }

    /** @param resource $stream */
    /**
     * @param  resource  $stream
     * @return array<string, mixed>|null
     */
    public static function read($stream, ?float $timeout = null): ?array
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $header = self::readExact($stream, 4, $deadline);
        if ($header === null) {
            return null;
        }

        $length = unpack('Nlength', $header)['length'] ?? null;
        if (! is_int($length) || $length > self::MAX_FRAME_BYTES) {
            throw new FrameProtocolError('frame exceeds maximum size');
        }

        $json = self::readExact($stream, $length, $deadline);
        if ($json === null) {
            throw new FrameProtocolError('unexpected EOF inside frame');
        }

        try {
            $message = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FrameProtocolError('frame payload is not valid JSON', previous: $exception);
        }
        if (! is_array($message)) {
            throw new FrameProtocolError('frame payload must be an object');
        }

        $normalized = [];
        foreach ($message as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** @param resource $stream */
    /** @param resource $stream */
    private static function waitForRead($stream, ?float $timeout): void
    {
        if ($timeout === null) {
            return;
        }

        $read = [$stream];
        $write = null;
        $except = null;
        $seconds = (int) floor($timeout);
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        $ready = stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === 0) {
            throw new FrameTimeout('frame read timed out');
        }
        if ($ready === false) {
            throw new FrameProtocolError('frame read select failed');
        }
    }

    /** @param resource $stream */
    /** @param resource $stream */
    private static function readExact($stream, int $length, ?float $deadline): ?string
    {
        if ($length === 0) {
            return '';
        }

        $result = '';
        while (strlen($result) < $length) {
            if ($deadline !== null) {
                self::waitForRead($stream, max(0.0, $deadline - microtime(true)));
            }
            $chunk = fread($stream, max(1, $length - strlen($result)));
            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    return $result === '' ? null : throw new FrameProtocolError('unexpected EOF');
                }

                throw new FrameProtocolError('frame read failed');
            }
            $result .= $chunk;
        }

        return $result;
    }

    /** @param resource $stream */
    /** @param resource $stream */
    private static function writeAll($stream, string $value): void
    {
        $offset = 0;
        while ($offset < strlen($value)) {
            $written = fwrite($stream, substr($value, $offset));
            if ($written === false || $written === 0) {
                throw new FrameProtocolError('frame write failed');
            }
            $offset += $written;
        }
        fflush($stream);
    }
}
