<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Supertext\ContaoTranslation\Sdk\LanguageCodes;

/**
 * Maps the `language` of a Contao website root to Supertext language codes:
 * regional targets (`de` => `de-DE`, `de-CH` kept, or the configured code) and
 * bare sources (`de-CH` => `de`). The rules are the Supertext SDK's LanguageCodes.
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
     * Targets need a region: kept and normalized (`de_ch` => `de-CH`), or a default
     * for a bare language (`de` => `de-DE`). A configured code wins.
     */
    public function target(string $language): string
    {
        return LanguageCodes::target($language, $this->map);
    }

    /**
     * Supertext expects the source as a bare language (`de`, not `de-CH`); a regional
     * source is rejected with INVALID_LANGUAGE_PAIR.
     */
    public function source(string $language): string
    {
        return LanguageCodes::source($language, $this->map);
    }

    /** @return 'default'|'less'|'more' */
    public function politeness(string $language): string
    {
        return $this->politeness[$language] ?? 'default';
    }
}
