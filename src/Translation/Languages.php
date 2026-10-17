<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Supertext\ContaoTranslation\Sdk\V1\Languages as SdkLanguages;
use Supertext\ContaoTranslation\Sdk\V1\SupertextException as SdkException;
use Supertext\ContaoTranslation\Supertext\SupertextException;

/**
 * Maps the `language` of a Contao website root to the Supertext language code, with the
 * Supertext SDK's rules (the same in every Supertext plugin), one mapping for both directions:
 *
 * 1. `language_map` in the bundle configuration (Contao language => Supertext code) wins, for
 *    automated setups; its value is sent as it is (for the source in its source form: `de-CH` => `de`).
 * 2. Else the root's *Supertext language* (`tl_page.supertext_language`, the select in the root's settings).
 * 3. Else a Contao language that is a Supertext code apart from case and `_`/`-`
 *    (`de-CH`, `fr_ch`, `es`; sources also by their language: `en-US` => `en`).
 * 4. Else the SDK's default target (`de` => `de-DE`, `en` => `en-US`, `zh-TW` => `zh-Hant`, `es-ES` => `es`, …).
 * 5. Else SupertextException `unsupported_language`, and nothing is sent (`de-LI`, `en-IE`, `gsw`, …
 *    need a Supertext language).
 *
 * Politeness: `politeness` in the configuration wins, else the root's *Formal or informal*.
 *
 * No Contao classes here: unit-tested (tests/Unit/LanguagesTest.php).
 */
final class Languages
{
    /** The places of Supertext's Swiss German dialects, for the select's names. */
    private const DIALECT_CITIES = [
        'chzh' => ['en' => 'Zurich', 'de' => 'Zürich', 'fr' => 'Zurich', 'it' => 'Zurigo'],
        'chbe' => ['en' => 'Bern', 'de' => 'Bern', 'fr' => 'Berne', 'it' => 'Berna'],
    ];

    /**
     * @param array<string, string>                   $map        Contao language => Supertext code (configuration)
     * @param array<string, 'default'|'less'|'more'> $politeness Contao language => politeness (configuration)
     */
    public function __construct(
        private readonly array $map = [],
        private readonly array $politeness = [],
    ) {
    }

    /**
     * @param string $chosen the root's Supertext language ('' = none chosen)
     *
     * @throws SupertextException unsupported_language
     */
    public function target(string $language, string $chosen = ''): string
    {
        try {
            return SdkLanguages::target($language, $this->mapping($language, $chosen));
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }

    /** The target code, or null when the language has no Supertext language (for the translate screen). */
    public function targetOrNull(string $language, string $chosen = ''): string|null
    {
        try {
            return $this->target($language, $chosen);
        } catch (SupertextException) {
            return null;
        }
    }

    /**
     * Supertext expects the source as a bare language (`de`, not `de-CH`).
     *
     * @throws SupertextException unsupported_language
     */
    public function source(string $language, string $chosen = ''): string
    {
        try {
            return SdkLanguages::source($language, $this->mapping($language, $chosen));
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }

    public function sourceOrNull(string $language, string $chosen = ''): string|null
    {
        try {
            return $this->source($language, $chosen);
        } catch (SupertextException) {
            return null;
        }
    }

    /** The `language_map` entry for a Contao language (it overrides the root's setting), else null. */
    public function configured(string $language): string|null
    {
        $wanted = self::key($language);

        foreach ($this->map as $from => $to) {
            if (self::key((string) $from) === $wanted && '' !== trim($to)) {
                return trim($to);
            }
        }

        return null;
    }

    /** The `politeness` entry for a Contao language (it overrides the root's setting), else null. */
    public function configuredPoliteness(string $language): string|null
    {
        return $this->politeness[$language] ?? null;
    }

    /**
     * The tone to send: `politeness` in the configuration (sent as it is), else the root's
     * *Formal or informal* when its Supertext language offers it, else `default`.
     *
     * @param string $chosenCode       the root's Supertext language ('' = none chosen)
     * @param string $chosenPoliteness the root's *Formal or informal* ('' = default)
     *
     * @return 'default'|'less'|'more'
     */
    public function politeness(string $language, string $chosenCode = '', string $chosenPoliteness = ''): string
    {
        if (isset($this->politeness[$language])) {
            return $this->politeness[$language];
        }

        if ('less' !== $chosenPoliteness && 'more' !== $chosenPoliteness) {
            return 'default';
        }

        // A tone left over from another Supertext language (the field is hidden then) is not sent.
        $target = $this->targetOrNull($language, $chosenCode);

        return null !== $target && self::supportsPoliteness($target) ? $chosenPoliteness : 'default';
    }

    /** Whether a Supertext target offers formal/informal (false for codes it doesn't list). */
    public static function supportsPoliteness(string $target): bool
    {
        return true === SdkLanguages::supportsPoliteness($target);
    }

    /**
     * Whether `politeness` in the configuration sets formal/informal for a language whose Supertext
     * target doesn't offer it (English, Swiss German, Romansh, Albanian, Serbian): Supertext would
     * answer `unsupported_feature`. The value is sent anyway (never dropped silently); the translate
     * screen warns about it.
     */
    public function politenessUnsupported(string $language, string $chosenCode = ''): bool
    {
        $target = $this->targetOrNull($language, $chosenCode);

        return null !== $target && 'default' !== $this->politeness($language, $chosenCode) && false === SdkLanguages::supportsPoliteness($target);
    }

    /**
     * The codes the select offers: Supertext's targets sorted by English name.
     *
     * @return list<string>
     */
    public static function targetCodes(): array
    {
        return array_column(SdkLanguages::targetOptions(), 'code');
    }

    /** What the select preselects for a root without a saved Supertext language: exact match, else the default, else ''. */
    public static function suggest(string $language): string
    {
        return SdkLanguages::suggestTarget($language) ?? '';
    }

    /**
     * The select's options, "Name (code)" with the name in the back-end language where PHP's intl
     * knows it (Locale::getDisplayName), else Supertext's English name.
     *
     * @return array<string, string> code => label
     */
    public static function targetLabels(string $locale = 'en'): array
    {
        $labels = [];

        foreach (SdkLanguages::targetOptions() as $option) {
            $labels[$option['code']] = \sprintf('%s (%s)', self::displayName($option['code'], $option['name'], $locale), $option['code']);
        }

        // Sorted by the shown (localized) name.
        if (class_exists(\Collator::class)) {
            (new \Collator($locale))->asort($labels);
        } else {
            asort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $labels;
    }

    private static function displayName(string $code, string $englishName, string $locale): string
    {
        if (!class_exists(\Locale::class)) {
            return $englishName;
        }

        // Swiss German dialects (gsw-u-sd-chzh): intl would show the subdivision code.
        if (str_contains($code, '-u-')) {
            $language = \Locale::getDisplayLanguage('gsw', $locale);
            $lang = substr($locale, 0, 2);
            $city = self::DIALECT_CITIES[substr($code, -4)][$lang] ?? null;

            if ('' === $language || 'gsw' === $language || null === $city) {
                return $englishName;
            }

            $name = $language.' ('.$city.')';
        } else {
            $name = \Locale::getDisplayName(str_replace('-', '_', $code), $locale);
        }

        if ('' === $name || strtolower($name) === strtolower(str_replace('-', '_', $code))) {
            return $englishName;
        }

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    /**
     * Configuration first (it wins), then the root's chosen code.
     *
     * @return array<string, string>
     */
    private function mapping(string $language, string $chosen): array
    {
        $mapping = $this->map;

        if ('' !== trim($chosen) && null === $this->configured($language)) {
            $mapping[$language] = trim($chosen);
        }

        return $mapping;
    }

    private static function key(string $code): string
    {
        return strtolower(str_replace('_', '-', trim($code)));
    }
}
