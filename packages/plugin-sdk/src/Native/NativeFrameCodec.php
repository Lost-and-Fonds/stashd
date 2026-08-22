<?php

declare(strict_types=1);

namespace Stashd\PluginSdk\Native;

use RuntimeException;

final class NativeFrameCodec
{
    /** @param resource $stream */
    public static function write($stream, array $message): void
    {
        $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $frame = pack('N', strlen($payload)) . $payload;
        $written = fwrite($stream, $frame);
        if ($written !== strlen($frame)) {
            throw new RuntimeException('native plugin IPC write failed');
        }
        fflush($stream);
    }

    /** @param resource $stream */
    public static function read($stream, float $timeout = 30.0): ?array
    {
        $deadline = microtime(true) + $timeout;
        $header = self::readBytes($stream, 4, $deadline);
        if ($header === '') {
            return null;
        }
        if (strlen($header) !== 4) {
            throw new RuntimeException('native plugin IPC frame header is truncated');
        }
        $length = unpack('Nlength', $header)['length'];
        if ($length < 2 || $length > 65536) {
            throw new RuntimeException('native plugin IPC frame is outside the size limit');
        }
        $payload = self::readBytes($stream, $length, $deadline);
        if (strlen($payload) !== $length) {
            throw new RuntimeException('native plugin IPC frame is truncated');
        }
        $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($message)) {
            throw new RuntimeException('native plugin IPC message is not an object');
        }

        return $message;
    }

    /** @param resource $stream */
    private static function readBytes($stream, int $length, float $deadline): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('native plugin IPC read timed out');
            }
            $read = [$stream];
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            if (stream_select($read, $write, $except, $seconds, $microseconds) === 0) {
                throw new RuntimeException('native plugin IPC read timed out');
            }
            $chunk = fread($stream, $length - strlen($result));
            if ($chunk === false || $chunk === '') {
                return $result;
            }
            $result .= $chunk;
        }

        return $result;
    }
}
