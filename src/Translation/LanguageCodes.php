<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Supertext\Sdk\LanguageCodes as Sdk;

/**
 * Turns CMS locales into the language codes Supertext accepts, with the Supertext SDK's
 * rules (Supertext\Sdk\LanguageCodes, the same in every Supertext plugin):
 *
 * - Source: the bare language ("en-US", "de_CH.UTF-8" => "en", "de").
 * - Target: a regional code. Supertext rejects bare targets such as "de" with
 *   INVALID_LANGUAGE_PAIR, so a bare locale gets a regional default ("de" =>
 *   "de-DE"); a locale with a region keeps it, normalized ("de_ch" => "de-CH").
 *   Unknown bare codes are sent unchanged.
 *
 * Contao locales may carry an encoding or modifier ("de_CH.UTF-8@euro"); it is removed
 * first. No Contao classes here: unit-tested (tests/Unit/LanguagesTest.php).
 */
final class LanguageCodes
{
    /** Regional default for bare target languages. */
    public const DEFAULT_REGIONS = Sdk::DEFAULT_REGIONS;

    /**
     * Source code: the bare language ("de-CH" => "de"); empty stays empty.
     * A per-language Supertext code ($override) wins, reduced to its language.
     */
    public static function source(string $locale, string $override = ''): string
    {
        return Sdk::source(self::clean(trim($override) !== '' ? $override : $locale));
    }

    /**
     * Target code: regional, normalized ("de_ch" => "de-CH", "zh-hant" => "zh-Hant",
     * "es-419" kept); a bare language gets its default region ("de" => "de-DE").
     * A per-language Supertext code ($override) wins.
     */
    public static function target(string $locale, string $override = ''): string
    {
        return Sdk::target(self::clean(trim($override) !== '' ? $override : $locale));
    }

    /** "de_CH.UTF-8@euro" => "de_CH"; separators collapsed ("de--ch" => "de-ch"). */
    private static function clean(string $locale): string
    {
        $locale = trim((string) preg_replace('/[.@].*$/', '', trim($locale)));

        return implode('-', array_values(array_filter(preg_split('/[-_]+/', $locale) ?: [], static fn (string $p): bool => '' !== $p)));
    }
}
