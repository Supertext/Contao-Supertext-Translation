<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Html;

/**
 * Hides Contao syntax that must not be translated (insert tags like `{{link::12}}`,
 * basic entities like `[nbsp]`) before a value is sent to Supertext, and puts it back
 * afterwards.
 *
 *  - In text: `<span translate="no" data-st-ph="N">N</span>`, an element the
 *    translator keeps in place within the sentence.
 *  - Inside a tag (e.g. `href="{{link_url::12}}"`): the plain marker `st-ph-N`, since an
 *    element can't go there and the HTML serializer would percent-encode braces in
 *    URLs. Translators leave attribute values alone.
 */
final class Protector
{
    /** Insert tags (also nested one level) and Contao's basic entities. */
    private const PATTERN = '/\{\{(?:[^{}]|\{\{[^{}]*\}\})*\}\}|\[(?:&|&amp;|lt|gt|nbsp|-)\]/';

    /** @var list<string> */
    private array $tokens = [];

    public function protect(string $value): string
    {
        // Split into tags and text; insert tags never contain "<" or ">".
        $parts = preg_split('/(<[^>]*>)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];

        foreach ($parts as $i => $part) {
            $inTag = 1 === $i % 2;
            $parts[$i] = (string) preg_replace_callback(
                self::PATTERN,
                function (array $m) use ($inTag): string {
                    $n = \count($this->tokens);
                    $this->tokens[] = $m[0];

                    return $inTag ? "st-ph-{$n}" : \sprintf('<span translate="no" data-st-ph="%d">%d</span>', $n, $n);
                },
                $part,
            );
        }

        return implode('', $parts);
    }

    /**
     * Element placeholders in parsed HTML have already been replaced by markers (see
     * {@see SegmentDocument::parse()}); this swaps all markers for the original tokens.
     *
     * @param list<int> $expected  placeholder indexes the value should contain
     * @param list<int> $missing   receives the indexes of tokens that did not come back
     */
    public function restore(string $value, array $expected = [], array|null &$missing = null): string
    {
        $missing = [];

        foreach ($expected as $n) {
            if (!str_contains($value, "\u{E000}{$n}\u{E001}") && !preg_match("/\\bst-ph-{$n}\\b/", $value)) {
                $missing[] = $n;
            }
        }

        $restored = (string) preg_replace_callback(
            '/\x{E000}(\d+)\x{E001}|\bst-ph-(\d+)\b/u',
            fn (array $m): string => $this->tokens[(int) ('' !== $m[1] ? $m[1] : $m[2])] ?? '',
            $value,
        );

        // A lost placeholder would silently drop a link or insert tag: append it so
        // the content stays functional, and let the caller report it.
        foreach ($missing as $n) {
            $restored .= $this->tokens[$n] ?? '';
        }

        return $restored;
    }

    /** @return list<int> indexes of the placeholders inside a protected value */
    public static function placeholdersIn(string $protected): array
    {
        preg_match_all('/data-st-ph="(\d+)"|\bst-ph-(\d+)\b/', $protected, $m, PREG_SET_ORDER);

        return array_values(array_unique(array_map(static fn ($x) => (int) ('' !== $x[1] ? $x[1] : $x[2]), $m)));
    }
}
