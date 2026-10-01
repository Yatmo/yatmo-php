<?php

declare(strict_types=1);

namespace Yatmo\Tests;

use Yatmo\Transport\TransportInterface;

/** Canned answers keyed by a substring of the URL; records every call. */
final class FakeTransport implements TransportInterface
{
    /** @var array<int, array{url: string, headers: array<string, string>}> */
    public array $calls = [];

    /** @param array<string, array{status?: int, body: mixed}> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        foreach ($this->answers as $needle => $answer) {
            if (str_contains($url, $needle)) {
                $body = $answer['body'];

                return ['status' => $answer['status'] ?? 200, 'body' => \is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)];
            }
        }

        return ['status' => 404, 'body' => '{"Error":"no canned answer"}'];
    }
}
