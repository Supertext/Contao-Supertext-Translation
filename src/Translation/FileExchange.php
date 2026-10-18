<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Supertext\ContaoTranslation\Sdk\Files\FileJob;
use Supertext\ContaoTranslation\Sdk\Files\FileSegment;
use Supertext\ContaoTranslation\Sdk\Files\ImportedSegment;
use Supertext\ContaoTranslation\Sdk\Files\TranslationFile;
use Supertext\ContaoTranslation\Sdk\Files\TranslationFileError;
use Supertext\ContaoTranslation\Sdk\Files\TranslationFiles;

/**
 * "Export for translation" and "Import translation": a page's texts as an XLIFF 1.2 or
 * HTML file (Supertext SDK `TranslationFiles`), translated outside the API, and the
 * translated file written back like an API translation.
 *
 * One job = one page (with its articles and content elements, no subpages) in one target
 * website root. The segments are exactly those of the API translation
 * ({@see PageTranslator::segments()}), so the import reuses the same write-back.
 *
 * Reference: `page:<page id>:<Contao language of the target root>`, e.g. `page:12:fr-CH`.
 *
 * No Contao classes here: unit-tested (tests/Unit/FileExchangeTest.php).
 */
class FileExchange
{
    /** The `plugin` of every job: this repository's name. */
    public const PLUGIN = 'Contao-Supertext-Translation';

    /** Uploads larger than this are refused before they are read. */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const FORMAT_XLIFF = 'xliff';

    public const FORMAT_HTML = 'html';

    private const REFERENCE = '/^page:([1-9]\d*):([A-Za-z0-9_-]+)$/';

    public function __construct(
        private readonly PageTranslator $translator,
        private readonly Languages $languages,
    ) {
    }

    public static function reference(int $pageId, string $language): string
    {
        return 'page:'.$pageId.':'.$language;
    }

    /** @return array{pageId: int, language: string}|null */
    public static function parseReference(string $reference): array|null
    {
        if (!preg_match(self::REFERENCE, $reference, $m)) {
            return null;
        }

        return ['pageId' => (int) $m[1], 'language' => $m[2]];
    }

    /**
     * The file for one page and the given target roots (XLIFF: one or more, HTML: exactly one),
     * or null when the page has nothing to translate.
     *
     * @param list<int> $rootIds target website roots ({@see PageTranslator::targetRoots()})
     *
     * @return array{content: string, filename: string, contentType: string}|null
     */
    public function export(int $pageId, array $rootIds, string $format): array|null
    {
        $roots = array_column($this->translator->targetRoots($pageId), null, 'id');
        $selected = array_values(array_filter(array_map(static fn (int $id) => $roots[$id] ?? null, $rootIds)));

        if ([] === $selected) {
            throw new \InvalidArgumentException('Choose at least one target language.');
        }

        if (self::FORMAT_HTML === $format && 1 !== \count($selected)) {
            throw new \InvalidArgumentException('An HTML file holds exactly one target language.');
        }

        $doc = $this->translator->segments($pageId);

        if (null === $doc || $doc->isEmpty()) {
            return null;
        }

        $segments = [];

        foreach ($doc->segments() as $key => $html) {
            $segments[] = new FileSegment((string) $key, $html, true);
        }

        $sourceLanguage = (string) $this->translator->sourceLanguage($pageId);
        $source = $this->languages->sourceOrNull($sourceLanguage, $this->translator->sourceSupertextLanguage($pageId)) ?? self::code($sourceLanguage);
        $jobs = [];

        foreach ($selected as $root) {
            $target = $this->languages->targetOrNull($root['language'], $root['supertextLanguage']) ?? self::code($root['language']);
            $jobs[] = new FileJob(self::PLUGIN, self::reference($pageId, $root['language']), $source, $target, $segments);
        }

        $name = 'contao-'.$pageId.(1 === \count($selected) ? '-'.self::fileSafe($selected[0]['language']) : '');

        if (self::FORMAT_HTML === $format) {
            return ['content' => TranslationFiles::exportHtml($jobs[0]), 'filename' => $name.'.html', 'contentType' => 'text/html; charset=utf-8'];
        }

        return ['content' => TranslationFiles::exportXliff($jobs), 'filename' => $name.'.xlf', 'contentType' => 'application/x-xliff+xml'];
    }

    /**
     * The jobs of an uploaded file.
     *
     * @return list<TranslationFile>
     *
     * @throws TranslationFileError invalid_file, missing_metadata, too_large
     */
    public function read(string $content): array
    {
        return TranslationFiles::read($content);
    }

    /**
     * Imports one job of a file into its page and target root.
     *
     * @param callable(int, int): bool $mayEdit whether the user may read the page (first argument) and edit the target website root (second)
     *
     * @return array{
     *     pageId: int|null,
     *     page: string,
     *     language: string,
     *     root: string,
     *     error: string|null,
     *     errorParams: list<string|int>,
     *     counts: array<string, int>,
     *     imported: int,
     *     targetPageId: int|null,
     * }
     */
    public function import(TranslationFile $file, bool $includeChanged, callable $mayEdit): array
    {
        $result = [
            'pageId' => null,
            'page' => $file->reference,
            'language' => $file->targetLanguage,
            'root' => '',
            'error' => null,
            'errorParams' => [],
            'counts' => array_fill_keys([ImportedSegment::OK, ImportedSegment::SOURCE_CHANGED, ImportedSegment::UNTRANSLATED, ImportedSegment::INVALID_MARKUP, ImportedSegment::MISSING], 0),
            'imported' => 0,
            'targetPageId' => null,
        ];

        if (self::PLUGIN !== $file->plugin) {
            return ['error' => 'fileOtherSystem', 'errorParams' => [$file->plugin]] + $result;
        }

        $reference = self::parseReference($file->reference);
        $title = null === $reference ? null : $this->translator->pageTitle($reference['pageId']);

        if (null === $reference || null === $title) {
            return ['error' => 'fileNotFound'] + $result;
        }

        $result['pageId'] = $reference['pageId'];
        $result['page'] = $title;
        $result['language'] = $reference['language'];
        $root = null;

        foreach ($this->translator->targetRoots($reference['pageId']) as $candidate) {
            if (0 === strcasecmp(self::code($candidate['language']), self::code($reference['language']))) {
                $root = $candidate;
                break;
            }
        }

        if (null === $root) {
            return ['error' => 'fileNoLanguage', 'errorParams' => [$reference['language']]] + $result;
        }

        $result['root'] = $root['title'];

        if (!$mayEdit($reference['pageId'], $root['id'])) {
            return ['error' => 'fileForbidden', 'errorParams' => [$reference['language']]] + $result;
        }

        $doc = $this->translator->segments($reference['pageId']);
        $current = [];

        foreach ($doc?->segments() ?? [] as $key => $html) {
            $current[] = new FileSegment((string) $key, $html, true);
        }

        $translations = [];

        foreach (TranslationFiles::importTranslations($file, $current) as $segment) {
            ++$result['counts'][$segment->status];

            if (null !== $segment->text && (ImportedSegment::OK === $segment->status || ($includeChanged && ImportedSegment::SOURCE_CHANGED === $segment->status))) {
                $translations[$segment->key] = $segment->text;
            }
        }

        $result['imported'] = \count($translations);

        if ([] === $translations) {
            return $result;
        }

        $report = $this->translator->writeImported($reference['pageId'], $root['id'], $translations);
        $target = $report->targets[$root['id']] ?? null;

        if (null !== $target && !$target['ok']) {
            $result['imported'] = 0;

            return ['error' => 'error_'.($target['errorCode'] ?? 'unknown'), 'errorParams' => $target['errorParams']] + $result;
        }

        $result['targetPageId'] = $target['pages'][0]['id'] ?? null;

        return $result;
    }

    /** A Contao language as a language tag (`de_CH` → `de-CH`), for languages without a Supertext code. */
    private static function code(string $language): string
    {
        return str_replace('_', '-', $language);
    }

    private static function fileSafe(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '', $value);
    }
}
