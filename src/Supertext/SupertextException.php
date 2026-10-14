<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

use Supertext\ContaoTranslation\Sdk\V1\SupertextException as SdkException;

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

    public static function fromSdk(SdkException $e): self
    {
        return new self($e->errorCode, $e->getMessage(), $e->status, $e, (string) $e->detail);
    }

    /**
     * Placeholders of the back end's `MSC.supertext.error_<code>` message: the language for
     * `unsupported_language` (the code that has no Supertext language), else the HTTP status.
     *
     * @return list<string>
     */
    public function messageParams(): array
    {
        if ('unsupported_language' === $this->errorCode) {
            $sdk = $this->getPrevious();

            return [$sdk instanceof SdkException ? (string) ($sdk->targetLang ?? $sdk->sourceLang) : ''];
        }

        return [(string) $this->httpStatus];
    }
}
