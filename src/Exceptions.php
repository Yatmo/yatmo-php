<?php

declare(strict_types=1);

namespace Yatmo\Exception;

/**
 * Base exception of the client. `$status` is the HTTP status: 400 point outside the country or bad
 * parameter, 401 key missing or unknown, 403 feature, country or origin not allowed by the key,
 * 429 quota, 0 network or timeout.
 */
class YatmoException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /** Builds the exception matching an HTTP status. */
    public static function fromStatus(int $status, string $message): self
    {
        return match ($status) {
            400 => new BadRequestException($status, $message),
            401 => new AuthenticationException($status, $message),
            403 => new ForbiddenException($status, $message),
            429 => new QuotaException($status, $message),
            default => new self($status, $message),
        };
    }
}

/** 400: the point lies outside the country, or a parameter is invalid. */
final class BadRequestException extends YatmoException
{
}

/** 401: the key is missing or unknown. */
final class AuthenticationException extends YatmoException
{
}

/** 403: the feature, the country or the origin is not allowed by the licence. */
final class ForbiddenException extends YatmoException
{
}

/** 429: the quota of the licence is reached. */
final class QuotaException extends YatmoException
{
}

/** 0: Yatmo could not be reached, or the answer could not be read. */
final class TransportException extends YatmoException
{
}
