<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

/**
 * Which fields of which table are translated, and how they are stored
 * (see {@see \Supertext\ContaoTranslation\Html\FieldCodec}).
 */
final class FieldMap
{
    public const DEFAULTS = [
        'tl_page' => [
            'title' => 'text',
            'pageTitle' => 'text',
            'description' => 'text',
        ],
        'tl_article' => [
            'title' => 'text',
            'teaser' => 'html',
        ],
        'tl_content' => [
            'headline' => 'inputUnit',
            'sectionHeadline' => 'inputUnit',
            'text' => 'html',
            'alt' => 'text',
            'imageTitle' => 'text',
            'caption' => 'html',
            'listitems' => 'list',
            'tableitems' => 'table',
            'summary' => 'text',
            'mooHeadline' => 'html',
            'titleText' => 'text',
            'linkTitle' => 'text',
            'playerTitle' => 'text',
            'playerCaption' => 'text',
        ],
    ];

    /** @param array<string, array<string, string|false|null>> $overrides */
    public function __construct(private readonly array $overrides = [])
    {
    }

    /** @return array<string, string> field => type */
    public function fieldsFor(string $table): array
    {
        $fields = array_merge(self::DEFAULTS[$table] ?? [], $this->overrides[$table] ?? []);

        // A field set to false/null in the configuration is not translated.
        return array_filter($fields, static fn ($type) => \is_string($type) && '' !== $type);
    }
}
