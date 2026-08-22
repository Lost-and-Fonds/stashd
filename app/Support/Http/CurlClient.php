<?php

declare(strict_types=1);

namespace App\Support\Http;

final class CurlClient
{
    /**
     * @param  non-empty-string  $method
     * @param  array<string, string>  $headers
     * @param  non-empty-string|null  $userAgent
     * @return array{status: int, body: string}|null null when curl_init fails
     */
    public static function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 30,
        ?string $userAgent = null,
    ): ?array {
        $curl = curl_init($url);

        if ($curl === false) {
            return null;
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
        ];

        if ($userAgent !== null) {
            $options[CURLOPT_USERAGENT] = $userAgent;
        }

        curl_setopt_array($curl, $options);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => $status > 0 ? $status : 0,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }
}
