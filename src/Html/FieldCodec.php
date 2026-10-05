<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Html;

/**
 * Turns Contao field values into translatable segments and back.
 *
 * Types:
 *  - `text`      plain text; stored in the field's text encoding (see ENCODINGS)
 *  - `html`      rich text (TinyMCE) or text that allows HTML
 *  - `inputUnit` serialized `['unit' => 'h2', 'value' => '…']` (headlines)
 *  - `list`      serialized list of HTML strings (list element)
 *  - `table`     serialized rows of HTML cells (table element)
 */
final class FieldCodec
{
    public const TYPES = ['text', 'html', 'inputUnit', 'list', 'table'];

    /**
     * How Contao stores plain text (mirrors Contao's Input::encodeInput()):
     *  - `raw`                Contao 6: as typed
     *  - `encodeAll`          Contao 5: # < > ( ) \ = " ' as numeric entities
     *  - `encodeLessThanSign` Contao 5 fields with `decodeEntities`: only <
     */
    public const ENCODINGS = ['raw', 'encodeAll', 'encodeLessThanSign'];

    /**
     * @return array<string, string> segment key => HTML
     */
    public static function segments(string $type, string $key, mixed $raw): array
    {
        $raw = (string) $raw;

        if ('' === trim($raw)) {
            return [];
        }

        return match ($type) {
            'text' => [$key => self::textToHtml($raw)],
            'html' => [$key => $raw],
            'inputUnit' => self::inputUnitSegments($key, $raw),
            'list' => self::listSegments($key, $raw),
            'table' => self::tableSegments($key, $raw),
            default => throw new \InvalidArgumentException(\sprintf('Unknown field type "%s".', $type)),
        };
    }

    /**
     * Returns the value with the translated segments applied. Segments without a
     * translation keep their source text.
     *
     * @param array<string, string> $translations
     */
    public static function apply(string $type, string $key, mixed $raw, array $translations, string $encoding = 'raw'): string
    {
        $raw = (string) $raw;

        switch ($type) {
            case 'text':
                return isset($translations[$key]) ? self::htmlToText($translations[$key], $encoding) : $raw;

            case 'html':
                return $translations[$key] ?? $raw;

            case 'inputUnit':
                $data = self::unserialize($raw);

                if (!\is_array($data)) {
                    return $raw;
                }

                if (isset($translations[$key.'.value'])) {
                    $data['value'] = self::htmlToText($translations[$key.'.value'], $encoding);
                }

                return serialize($data);

            case 'list':
                $data = self::unserialize($raw);

                if (!\is_array($data)) {
                    return $raw;
                }

                foreach ($data as $i => $item) {
                    $data[$i] = $translations[$key.'.'.$i] ?? $item;
                }

                return serialize($data);

            case 'table':
                $data = self::unserialize($raw);

                if (!\is_array($data)) {
                    return $raw;
                }

                foreach ($data as $r => $row) {
                    if (!\is_array($row)) {
                        continue;
                    }

                    foreach ($row as $c => $cell) {
                        $data[$r][$c] = $translations[$key.'.'.$r.'.'.$c] ?? $cell;
                    }
                }

                return serialize($data);
        }

        throw new \InvalidArgumentException(\sprintf('Unknown field type "%s".', $type));
    }

    /** Stored plain text (in any of the encodings) → HTML for the document. */
    public static function textToHtml(string $stored): string
    {
        return htmlspecialchars(self::decode($stored), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Translated HTML → plain text, encoded the way Contao stores the field. */
    public static function htmlToText(string $html, string $encoding = 'raw'): string
    {
        $text = self::decode(strip_tags($html));
        $text = trim((string) preg_replace('/[ \t\r\n]+/u', ' ', $text));

        return match ($encoding) {
            'raw' => $text,
            'encodeAll' => str_replace(
                ['#', '<', '>', '(', ')', '\\', '=', '"', "'"],
                ['&#35;', '&#60;', '&#62;', '&#40;', '&#41;', '&#92;', '&#61;', '&#34;', '&#39;'],
                $text,
            ),
            'encodeLessThanSign' => str_replace('<', '&#60;', $text),
            default => throw new \InvalidArgumentException(\sprintf('Unknown text encoding "%s".', $encoding)),
        };
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function inputUnitSegments(string $key, string $raw): array
    {
        $data = self::unserialize($raw);

        if (!\is_array($data) || !\is_string($data['value'] ?? null) || '' === trim($data['value'])) {
            return [];
        }

        return [$key.'.value' => self::textToHtml($data['value'])];
    }

    private static function listSegments(string $key, string $raw): array
    {
        $data = self::unserialize($raw);
        $out = [];

        foreach (\is_array($data) ? $data : [] as $i => $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[$key.'.'.$i] = $item;
            }
        }

        return $out;
    }

    private static function tableSegments(string $key, string $raw): array
    {
        $data = self::unserialize($raw);
        $out = [];

        foreach (\is_array($data) ? $data : [] as $r => $row) {
            foreach (\is_array($row) ? $row : [] as $c => $cell) {
                if (\is_string($cell) && '' !== trim($cell)) {
                    $out[$key.'.'.$r.'.'.$c] = $cell;
                }
            }
        }

        return $out;
    }

    private static function unserialize(string $raw): mixed
    {
        if (!str_starts_with($raw, 'a:')) {
            return null;
        }

        // Never instantiate objects from database content.
        $data = @unserialize($raw, ['allowed_classes' => false]);

        return false === $data ? null : $data;
    }
}
