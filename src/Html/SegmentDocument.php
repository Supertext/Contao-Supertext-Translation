<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Html;

/**
 * One HTML document per page and target language. Every translatable value becomes a
 * `<div data-st-id="key">…</div>` holding its HTML; Supertext keeps markup and
 * attributes and translates the text nodes.
 */
final class SegmentDocument
{
    public const ID_ATTRIBUTE = 'data-st-id';

    /** @var array<string, string> key => protected HTML */
    private array $segments = [];

    /** @var array<string, list<int>> key => placeholder indexes */
    private array $placeholders = [];

    private readonly Protector $protector;

    public function __construct()
    {
        $this->protector = new Protector();
    }

    /**
     * @param string $html value as stored by Contao (plain fields are HTML-encoded text)
     */
    public function add(string $key, string $html): void
    {
        if ('' === trim(strip_tags($html, '<img>'))) {
            return;
        }

        $protected = $this->protector->protect($html);
        $this->segments[$key] = $protected;
        $this->placeholders[$key] = Protector::placeholdersIn($protected);
    }

    public function isEmpty(): bool
    {
        return [] === $this->segments;
    }

    public function count(): int
    {
        return \count($this->segments);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->segments);
    }

    public function toHtml(): string
    {
        $body = '';

        foreach ($this->segments as $key => $html) {
            $body .= \sprintf("<div %s=\"%s\">%s</div>\n", self::ID_ATTRIBUTE, htmlspecialchars($key, ENT_QUOTES), $html);
        }

        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head><body>\n".$body.'</body></html>';
    }

    /**
     * Parses the translated document.
     *
     * @return array{translations: array<string, string>, missing: list<string>, lostPlaceholders: list<string>}
     */
    public function parse(string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);

        // Replace placeholders with private-use markers so the tokens can be put back
        // after serialisation without being HTML-escaped.
        foreach (iterator_to_array($xpath->query('//*[@data-st-ph]') ?: []) as $node) {
            \assert($node instanceof \DOMElement);
            $marker = "\u{E000}".(int) $node->getAttribute('data-st-ph')."\u{E001}";
            $node->parentNode?->replaceChild($dom->createTextNode($marker), $node);
        }

        $translations = [];
        $lostPlaceholders = [];

        foreach ($xpath->query('//*[@'.self::ID_ATTRIBUTE.']') ?: [] as $node) {
            \assert($node instanceof \DOMElement);
            $key = $node->getAttribute(self::ID_ATTRIBUTE);

            if (!isset($this->segments[$key])) {
                continue;
            }

            $inner = '';

            foreach ($node->childNodes as $child) {
                $inner .= $dom->saveHTML($child);
            }

            $inner = trim($inner);

            if ('' === $inner) {
                continue;
            }

            $translations[$key] = $this->protector->restore($inner, $this->placeholders[$key], $lost);

            if ([] !== $lost) {
                $lostPlaceholders[] = $key;
            }
        }

        return [
            'translations' => $translations,
            'missing' => array_values(array_diff(array_keys($this->segments), array_keys($translations))),
            'lostPlaceholders' => $lostPlaceholders,
        ];
    }
}
