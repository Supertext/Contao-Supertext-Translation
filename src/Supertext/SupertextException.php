<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

/**
 * An error from the Supertext API or while talking to it. `$errorCode` is a stable
 * machine-readable code, the Supertext SDK's (same set in every Supertext plugin); the
 * SDK's exception is kept as the previous exception.
 */
final class SupertextException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int|null $httpStatus = null,
        \Throwable|null $previous = null,
        /** Untranslated detail (the API's answer or the transport error), also part of the message. */
        public readonly string $detail = '',
    ) {
        parent::__construct($message, 0, $previous);
    }
}
