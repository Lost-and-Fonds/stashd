<?php

declare(strict_types=1);

namespace Tests\Support\RecordsAndArchives;

/** Real HTTP application process with a hard memory ceiling and disposable Vault. */
final class BrowserServer
{
    private mixed $process;
    public readonly string $url;

    public function __construct(string $root, string $log, array $environment = [])
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->url = 'http://' . $address;
        $pipes = [];
        $this->process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=32M', '-S', $address, '-t', 'public', 'public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname(__DIR__, 3),
            [
                ...getenv(),
                'VAULT_ROOT' => $root,
                'ENVIRONMENT' => 'local',
                'VAULT_BROWSER_ENABLED' => 'true',
                'SIGNING_KEY' => base64_encode(str_repeat('s', 32)),
                'ENCRYPTION_KEY' => base64_encode(str_repeat('e', 32)),
                ...$environment,
            ],
        );
        if (! is_resource($this->process)) {
            throw new \RuntimeException('Could not launch HTTP application.');
        }
        fclose($pipes[0]);
        for ($i = 0; $i < 100; $i++) {
            set_error_handler(static fn (): bool => true);
            $ready = stream_socket_client('tcp://' . $address, timeout: 0.1);
            restore_error_handler();
            if ($ready !== false) {
                fclose($ready);
                return;
            }
            usleep(20_000);
        }
        throw new \RuntimeException('HTTP application did not start: ' . file_get_contents($log));
    }

    public function request(string $path, ?string $destination = null, string $method = 'GET'): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'method' => $method, 'timeout' => 120, 'header' => 'Accept: text/html']]);
        $input = fopen($this->url . $path, 'rb', false, $context);
        $headers = stream_get_meta_data($input)['wrapper_data'];
        if ($destination === null) {
            $body = stream_get_contents($input);
        } else {
            $output = fopen($destination, 'wb');
            stream_copy_to_stream($input, $output);
            fclose($output);
            $body = '';
        }
        fclose($input);
        return ['status' => (int) explode(' ', $headers[0])[1], 'body' => $body, 'headers' => implode("\n", $headers)];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
