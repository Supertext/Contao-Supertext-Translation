<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

/**
 * A translation run that can't continue for one language. The back end shows
 * `MSC.supertext.error_<errorCode>` with `$params`; the message is for logs.
 */
final class TranslationException extends \RuntimeException
{
    /** @param list<string> $params */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
