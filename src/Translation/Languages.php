<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

/**
 * Maps the `language` of a Contao website root to Supertext language codes.
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

    /** Targets keep their region (`de-CH`). */
    public function target(string $language): string
    {
        return $this->map[$language] ?? str_replace('_', '-', trim($language));
    }

    /**
     * Supertext expects the source as a primary subtag (`de`, not `de-CH`); a regional
     * source is rejected with INVALID_LANGUAGE_PAIR.
     */
    public function source(string $language): string
    {
        return strtolower((string) preg_split('/[-_]/', $this->target($language))[0]);
    }

    /** @return 'default'|'less'|'more' */
    public function politeness(string $language): string
    {
        return $this->politeness[$language] ?? 'default';
    }
}
