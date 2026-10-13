<?php

declare(strict_types=1);

namespace Supertext\Sdk;

/**
 * Language codes for the Supertext AI file API.
 *
 * Supertext wants the source as a bare language ("en") and the target as a regional code ("de-DE").
 * A bare target or a regional source is rejected with INVALID_LANGUAGE_PAIR, the most common
 * integration bug. CMS locales come in every shape ("de", "de_CH", "pt-br"), so they are normalized
 * and bare targets get a default region.
 *
 * Overrides map a CMS locale to the Supertext code to use for it (e.g. "de" => "de-CH" for a Swiss site,
 * "no" => "nb-NO"). A key matches when it normalizes to the same code.
 */
final class LanguageCodes
{
    /** Region used for a bare target language. */
    public const DEFAULT_REGIONS = [
        'ar' => 'ar-SA', 'bg' => 'bg-BG', 'cs' => 'cs-CZ', 'da' => 'da-DK', 'de' => 'de-DE', 'el' => 'el-GR',
        'en' => 'en-US', 'es' => 'es-ES', 'et' => 'et-EE', 'fi' => 'fi-FI', 'fr' => 'fr-FR', 'hr' => 'hr-HR',
        'hu' => 'hu-HU', 'it' => 'it-IT', 'ja' => 'ja-JP', 'ko' => 'ko-KR', 'lt' => 'lt-LT', 'lv' => 'lv-LV',
        'nb' => 'nb-NO', 'nl' => 'nl-NL', 'pl' => 'pl-PL', 'pt' => 'pt-PT', 'ro' => 'ro-RO', 'ru' => 'ru-RU',
        'sk' => 'sk-SK', 'sl' => 'sl-SI', 'sr' => 'sr-RS', 'sv' => 'sv-SE', 'th' => 'th-TH', 'tr' => 'tr-TR',
        'uk' => 'uk-UA', 'vi' => 'vi-VN', 'zh' => 'zh-CN',
    ];

    private const VALID = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$/';

    /** "de_ch" → "de-CH", "zh-hant" → "zh-Hant", "es-419" stays. */
    public static function normalize(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        $parts = explode('-', str_replace('_', '-', $code));
        $parts[0] = strtolower($parts[0]);
        for ($i = 1, $n = \count($parts); $i < $n; $i++) {
            $part = $parts[$i];
            if (preg_match('/^[A-Za-z]{2}$/', $part) === 1) {
                $parts[$i] = strtoupper($part); // region
            } elseif (preg_match('/^[A-Za-z]{4}$/', $part) === 1) {
                $parts[$i] = ucfirst(strtolower($part)); // script
            }
        }

        return implode('-', $parts);
    }

    /**
     * Source language as Supertext wants it: the bare language ("en-GB" → "en"). Empty stays empty (auto-detect).
     *
     * @param array<string, string> $overrides CMS locale → Supertext code
     */
    public static function source(string $code, array $overrides = []): string
    {
        $mapped = self::override($code, $overrides) ?? $code;

        return explode('-', self::normalize($mapped))[0];
    }

    /**
     * Target language as Supertext wants it: a regional code ("de" → "de-DE"). Unknown bare languages stay as they are.
     *
     * @param array<string, string> $overrides CMS locale → Supertext code
     */
    public static function target(string $code, array $overrides = []): string
    {
        $normalized = self::normalize(self::override($code, $overrides) ?? $code);

        return str_contains($normalized, '-') ? $normalized : (self::DEFAULT_REGIONS[$normalized] ?? $normalized);
    }

    /** Whether the code (after normalize) looks like a language code Supertext can understand. */
    public static function isValid(string $code): bool
    {
        return preg_match(self::VALID, self::normalize($code)) === 1;
    }

    /**
     * The non-blank override for $code, if any.
     *
     * @param array<string, string> $overrides
     */
    private static function override(string $code, array $overrides): ?string
    {
        $normalized = self::normalize($code);
        foreach ($overrides as $key => $value) {
            if (self::normalize((string) $key) === $normalized && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
