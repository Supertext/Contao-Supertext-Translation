<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

/**
 * An error from the Supertext API or while talking to it. `$errorCode` is a stable
 * machine-readable code (same set as the other Supertext CMS plugins).
 */
final class SupertextException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int|null $httpStatus = null,
        \Throwable|null $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromStatus(int $status, string $body = ''): self
    {
        [$code, $message] = match (true) {
            401 === $status, 403 === $status => ['authentication_failure', 'Authentication failure. Please check your Supertext API key.'],
            404 === $status => ['not_found', 'The requested Supertext resource was not found.'],
            413 === $status => ['payload_too_large', 'The document is too large for Supertext to translate.'],
            429 === $status => ['too_many_requests', 'Too many requests to Supertext. Please try again shortly.'],
            \in_array($status, [500, 502, 503], true) => ['service_unavailable', 'Supertext service unavailable.'],
            default => ['unexpected_status', \sprintf('Supertext sent an unexpected status code %d.', $status)],
        };

        $detail = mb_substr(trim(strip_tags($body)), 0, 200);

        return new self($code, '' !== $detail ? $message.' — '.$detail : $message, $status);
    }
}
