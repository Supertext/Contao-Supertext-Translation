<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Supertext\ContaoTranslation\Sdk\V1\Languages as SdkLanguages;
use Supertext\ContaoTranslation\Sdk\V1\SupertextException as SdkException;
use Supertext\ContaoTranslation\Supertext\SupertextException;

/**
 * Maps the `language` of a Contao website root to the Supertext language code, with the
 * Supertext SDK's rules (the same in every Supertext plugin), without guessing:
 *
 * 1. `language_map` (Contao language => Supertext code) wins; its value is sent as it is
 *    (for the source in its source form: `de-CH` => `de`).
 * 2. Otherwise a Contao language that is a Supertext code apart from case and `_`/`-`
 *    (`de-CH`, `fr_ch`, `es`; sources also by their language: `en-US` => `en`).
 * 3. Otherwise SupertextException `unsupported_language`, and nothing is sent. There are no
 *    default regions: a root with language `de` needs `language_map: { de: de-CH }` (or de-DE, de-AT).
 *
 * No Contao classes here: unit-tested (tests/Unit/LanguagesTest.php).
 */
final class Languages
{
    /**
     * @param array<string, string>                   $map        Contao language => Supertext code
     * @param array<string, 'default'|'less'|'more'> $politeness Contao language => politeness
     */
    public function __construct(
        private readonly array $map = [],
        private readonly array $politeness = [],
    ) {
    }

    /**
     * @throws SupertextException unsupported_language
     */
    public function target(string $language): string
    {
        try {
            return SdkLanguages::target($language, $this->map);
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }

    /** The target code, or null when the language has no Supertext language (for the translate screen). */
    public function targetOrNull(string $language): string|null
    {
        try {
            return $this->target($language);
        } catch (SupertextException) {
            return null;
        }
    }

    /**
     * Supertext expects the source as a bare language (`de`, not `de-CH`).
     *
     * @throws SupertextException unsupported_language
     */
    public function source(string $language): string
    {
        try {
            return SdkLanguages::source($language, $this->map);
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }

    public function sourceOrNull(string $language): string|null
    {
        try {
            return $this->source($language);
        } catch (SupertextException) {
            return null;
        }
    }

    /** @return 'default'|'less'|'more' */
    public function politeness(string $language): string
    {
        return $this->politeness[$language] ?? 'default';
    }

    /**
     * Whether formal/informal is set for a language whose Supertext target doesn't offer it
     * (English, Swiss German, Romansh, Albanian, Serbian): Supertext would answer
     * `unsupported_feature`. The value is sent anyway (never dropped silently); the translate
     * screen warns about it.
     */
    public function politenessUnsupported(string $language): bool
    {
        $target = $this->targetOrNull($language);

        return null !== $target && 'default' !== $this->politeness($language) && false === SdkLanguages::supportsPoliteness($target);
    }
}
