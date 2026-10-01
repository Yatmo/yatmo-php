<?php

declare(strict_types=1);

namespace Yatmo\Transport;

use Yatmo\Exception\TransportException;

/** What the client needs from HTTP: a GET with headers, giving back the status and the body. */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws TransportException when the request cannot be made
     */
    public function get(string $url, array $headers, int $timeoutSeconds): array;
}

/** Default transport on ext-curl. */
final class CurlTransport implements TransportInterface
{
    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        if (!\function_exists('curl_init')) {
            throw new TransportException(0, 'Yatmo: ext-curl is missing; pass another Yatmo\Transport\TransportInterface to the client');
        }
        $handle = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new TransportException(0, 'Yatmo: ' . ($error !== '' ? $error : 'network error'));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $body];
    }
}

/** Transport on the stream wrapper (`file_get_contents`), for hosts without ext-curl. */
final class StreamTransport implements TransportInterface
{
    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => $lines, 'timeout' => $timeoutSeconds, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();
            throw new TransportException(0, 'Yatmo: ' . ($error['message'] ?? 'network error'));
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }
}
