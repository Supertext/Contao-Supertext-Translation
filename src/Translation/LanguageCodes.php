<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

/**
 * Turns CMS locales into the language codes Supertext accepts.
 *
 * - Source: the bare language ("en-US", "de_CH.UTF-8" => "en", "de").
 * - Target: a regional code. Supertext rejects bare targets such as "de" with
 *   INVALID_LANGUAGE_PAIR, so a bare locale gets a regional default ("de" =>
 *   "de-DE"); a locale with a region keeps it, normalized ("de_ch" => "de-CH").
 *   Unknown bare codes are sent unchanged.
 *
 * No Contao classes here: unit-tested (tests/Unit/LanguagesTest.php).
 */
final class LanguageCodes
{
    /** Regional default for bare target languages. */
    public const DEFAULT_REGIONS = [
        'ar' => 'ar-SA', 'bg' => 'bg-BG', 'cs' => 'cs-CZ', 'da' => 'da-DK', 'de' => 'de-DE',
        'el' => 'el-GR', 'en' => 'en-US', 'es' => 'es-ES', 'et' => 'et-EE', 'fi' => 'fi-FI',
        'fr' => 'fr-FR', 'hr' => 'hr-HR', 'hu' => 'hu-HU', 'it' => 'it-IT', 'ja' => 'ja-JP',
        'ko' => 'ko-KR', 'lt' => 'lt-LT', 'lv' => 'lv-LV', 'nb' => 'nb-NO', 'nl' => 'nl-NL',
        'pl' => 'pl-PL', 'pt' => 'pt-PT', 'ro' => 'ro-RO', 'ru' => 'ru-RU', 'sk' => 'sk-SK',
        'sl' => 'sl-SI', 'sr' => 'sr-RS', 'sv' => 'sv-SE', 'th' => 'th-TH', 'tr' => 'tr-TR',
        'uk' => 'uk-UA', 'vi' => 'vi-VN', 'zh' => 'zh-CN',
    ];

    /**
     * Source code: the bare language ("de-CH" => "de"); empty stays empty.
     * A per-language Supertext code ($override) wins, reduced to its language.
     */
    public static function source(string $locale, string $override = ''): string
    {
        return self::parts(trim($override) !== '' ? $override : $locale)[0] ?? '';
    }

    /**
     * Target code: regional, normalized ("de_ch" => "de-CH", "zh-hant" => "zh-Hant",
     * "es-419" kept); a bare language gets its default region ("de" => "de-DE").
     * A per-language Supertext code ($override) wins.
     */
    public static function target(string $locale, string $override = ''): string
    {
        $parts = self::parts(trim($override) !== '' ? $override : $locale);
        if ($parts === []) {
            return '';
        }
        if (count($parts) === 1) {
            return self::DEFAULT_REGIONS[$parts[0]] ?? $parts[0];
        }
        foreach ($parts as $i => $part) {
            if ($i === 0) {
                continue;
            }
            $parts[$i] = match (true) {
                strlen($part) === 4 && ctype_alpha($part) => ucfirst($part),
                strlen($part) === 2 && ctype_alpha($part) => strtoupper($part),
                default => $part,
            };
        }
        return implode('-', $parts);
    }

    /** @return list<string> lower-cased subtags without encoding or modifier ("de_CH.UTF-8@euro" => ["de", "ch"]) */
    private static function parts(string $locale): array
    {
        $locale = strtolower(trim((string)preg_replace('/[.@].*$/', '', trim($locale))));
        if ($locale === '') {
            return [];
        }
        return array_values(array_filter(preg_split('/[-_]+/', $locale) ?: [], static fn(string $p): bool => $p !== ''));
    }
}
