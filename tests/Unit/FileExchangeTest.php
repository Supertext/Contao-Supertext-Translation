<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\Html\FieldCodec;
use Supertext\ContaoTranslation\Html\SegmentDocument;
use Supertext\ContaoTranslation\Sdk\Files\FileJob;
use Supertext\ContaoTranslation\Sdk\Files\FileSegment;
use Supertext\ContaoTranslation\Sdk\Files\TranslationFileError;
use Supertext\ContaoTranslation\Sdk\Files\TranslationFiles;
use Supertext\ContaoTranslation\Translation\FileExchange;
use Supertext\ContaoTranslation\Translation\Languages;
use Supertext\ContaoTranslation\Translation\PageTranslator;
use Supertext\ContaoTranslation\Translation\TranslationReport;

/**
 * Export for translation / Import translation glue, with a fake PageTranslator
 * (the write-back itself is covered by the integration tests).
 */
final class FileExchangeTest extends TestCase
{
    public function testReferenceRoundTrip(): void
    {
        $this->assertSame('page:12:fr-CH', FileExchange::reference(12, 'fr-CH'));
        $this->assertSame(['pageId' => 12, 'language' => 'fr-CH'], FileExchange::parseReference('page:12:fr-CH'));
        $this->assertSame(['pageId' => 7, 'language' => 'de_CH'], FileExchange::parseReference(FileExchange::reference(7, 'de_CH')));

        foreach (['', 'page:12', 'page:0:de', 'page:x:de', 'entry:12:de', 'page:12:de:x', 'page:12:d e'] as $invalid) {
            $this->assertNull(FileExchange::parseReference($invalid), $invalid);
        }
    }

    public function testExportsTheCollectorsSegmentsWithMappedLanguages(): void
    {
        $pages = new FakePages();
        $exchange = new FileExchange($pages, new Languages(['fr-CH' => 'fr-FR']));

        $file = $exchange->export(12, [3, 4], FileExchange::FORMAT_XLIFF);

        $this->assertNotNull($file);
        $this->assertSame('contao-12.xlf', $file['filename']);
        $this->assertSame('application/x-xliff+xml', $file['contentType']);

        $jobs = TranslationFiles::read($file['content']);
        $this->assertCount(2, $jobs);
        $this->assertSame([FileExchange::PLUGIN, 'page:12:fr-CH', 'en', 'fr-FR'], [$jobs[0]->plugin, $jobs[0]->reference, $jobs[0]->sourceLanguage, $jobs[0]->targetLanguage]);
        // de-LI has no Supertext code: the export still works, with the Contao code.
        $this->assertSame(['page:12:de-LI', 'de-LI'], [$jobs[1]->reference, $jobs[1]->targetLanguage]);
        $this->assertSame(array_keys($pages->doc()->segments()), array_map(static fn ($u) => $u->key, $jobs[0]->units));
    }

    public function testHtmlExportHasExactlyOneLanguage(): void
    {
        $exchange = new FileExchange(new FakePages(), new Languages());

        $file = $exchange->export(12, [3], FileExchange::FORMAT_HTML);
        $this->assertSame('contao-12-fr-CH.html', $file['filename']);
        $this->assertSame('text/html; charset=utf-8', $file['contentType']);
        $this->assertSame('page:12:fr-CH', TranslationFiles::read($file['content'])[0]->reference);

        $this->expectException(\InvalidArgumentException::class);
        $exchange->export(12, [3, 4], FileExchange::FORMAT_HTML);
    }

    public function testNothingToExport(): void
    {
        $pages = new FakePages();
        $pages->empty = true;

        $this->assertNull((new FileExchange($pages, new Languages()))->export(12, [3], FileExchange::FORMAT_XLIFF));
    }

    public function testImportWritesOkSegmentsWithInsertTagsRestored(): void
    {
        $pages = new FakePages();
        $exchange = new FileExchange($pages, new Languages());
        $html = $exchange->export(12, [3], FileExchange::FORMAT_HTML)['content'];
        $translated = str_replace(['About us', 'Read', 'One'], ['À propos', 'Lisez', 'Un'], $html);

        $result = $exchange->import(TranslationFiles::read($translated)[0], false, static fn () => true);

        $this->assertNull($result['error']);
        $this->assertSame(3, $result['imported']);
        $this->assertSame(['ok' => 3, 'source_changed' => 0, 'untranslated' => 0, 'invalid_markup' => 0, 'missing' => 0], $result['counts']);
        $this->assertSame('About us', $result['page']);
        $this->assertSame(['pageId' => 12, 'rootId' => 3], array_intersect_key($pages->written[0], ['pageId' => 1, 'rootId' => 1]));
        $this->assertSame('À propos', $pages->written[0]['translations']['p.12.title']);
        $this->assertSame(77, $result['targetPageId']);

        // The written texts are protected HTML, put back into Contao syntax like API results.
        $restored = $pages->doc()->restore($pages->written[0]['translations']);
        $this->assertSame('<p>Lisez <a href="{{link_url::5}}">more</a>{{br}}</p>', $restored['c.20.text']);
    }

    public function testSourceChangedOnlyWithTheOption(): void
    {
        $pages = new FakePages();
        $exchange = new FileExchange($pages, new Languages());
        $xliff = $exchange->export(12, [3], FileExchange::FORMAT_XLIFF)['content'];
        $translated = self::translateXliff($xliff);

        // The title is edited after the export.
        $pages->title = 'About our company';

        $skipped = $exchange->import(TranslationFiles::read($translated)[0], false, static fn () => true);
        $this->assertSame(1, $skipped['counts']['source_changed']);
        $this->assertSame(2, $skipped['imported']);
        $this->assertArrayNotHasKey('p.12.title', $pages->written[0]['translations']);

        $included = $exchange->import(TranslationFiles::read($translated)[0], true, static fn () => true);
        $this->assertSame(3, $included['imported']);
        $this->assertArrayHasKey('p.12.title', $pages->written[1]['translations']);
    }

    public function testUntranslatedInvalidAndMissingAreNotWritten(): void
    {
        $pages = new FakePages();
        $exchange = new FileExchange($pages, new Languages());
        $html = $exchange->export(12, [3], FileExchange::FORMAT_HTML)['content'];
        // Markup the source doesn't have: invalid_markup.
        $html = str_replace('>One<', '><script>x</script>Un<', $html);

        // A text added since the export: missing.
        $pages->extra = true;
        $result = $exchange->import(TranslationFiles::read($html)[0], true, static fn () => true);
        $this->assertSame(1, $result['counts']['invalid_markup']);
        $this->assertSame(1, $result['counts']['missing']);
        $this->assertArrayNotHasKey('c.21.listitems.0', $pages->written[0]['translations']);
        $this->assertArrayNotHasKey('c.22.text', $pages->written[0]['translations']);

        // XLIFF without targets: untranslated, nothing written.
        $pages->extra = false;
        $before = \count($pages->written);
        $result = $exchange->import(TranslationFiles::read($exchange->export(12, [3], FileExchange::FORMAT_XLIFF)['content'])[0], true, static fn () => true);
        $this->assertSame(3, $result['counts']['untranslated']);
        $this->assertSame(0, $result['imported']);
        $this->assertCount($before, $pages->written);
    }

    public function testRefusesOtherSystemsUnknownPagesMissingLanguagesAndForbiddenRoots(): void
    {
        $pages = new FakePages();
        $exchange = new FileExchange($pages, new Languages());
        $file = static fn (string $plugin, string $reference) => TranslationFiles::read(TranslationFiles::exportHtml(
            new FileJob($plugin, $reference, 'en', 'fr-CH', [new FileSegment('p.12.title', 'About us', true)]),
        ))[0];

        $this->assertSame(['fileOtherSystem', ['TYPO3-Supertext-Translation']], self::error($exchange->import($file('TYPO3-Supertext-Translation', 'page:12:fr-CH'), false, static fn () => true)));
        $this->assertSame('fileNotFound', $exchange->import($file(FileExchange::PLUGIN, 'page:99:fr-CH'), false, static fn () => true)['error']);
        $this->assertSame('fileNotFound', $exchange->import($file(FileExchange::PLUGIN, 'node:12'), false, static fn () => true)['error']);
        $this->assertSame(['fileNoLanguage', ['it-CH']], self::error($exchange->import($file(FileExchange::PLUGIN, 'page:12:it-CH'), false, static fn () => true)));
        $this->assertSame(['fileForbidden', ['fr-CH']], self::error($exchange->import($file(FileExchange::PLUGIN, 'page:12:fr-CH'), false, static fn (int $page, int $root) => 3 !== $root)));
        $this->assertSame([], $pages->written);
    }

    public function testWriteErrorsAreReported(): void
    {
        $pages = new FakePages();
        $pages->failWith = 'parent_not_translated';
        $exchange = new FileExchange($pages, new Languages());
        $html = str_replace('About us', 'À propos', $exchange->export(12, [3], FileExchange::FORMAT_HTML)['content']);

        $result = $exchange->import(TranslationFiles::read($html)[0], false, static fn () => true);

        $this->assertSame('error_parent_not_translated', $result['error']);
        $this->assertSame(0, $result['imported']);
    }

    public function testTooLargeFilesAreRefused(): void
    {
        $this->assertSame(10 * 1024 * 1024, FileExchange::MAX_UPLOAD_BYTES);

        try {
            (new FileExchange(new FakePages(), new Languages()))->read(str_repeat('x', TranslationFiles::MAX_FILE_CHARACTERS + 1));
            $this->fail('expected too_large');
        } catch (TranslationFileError $e) {
            $this->assertSame(TranslationFileError::TOO_LARGE, $e->errorCode);
        }
    }

    /** @return array{0: string|null, 1: list<string|int>} */
    private static function error(array $result): array
    {
        return [$result['error'], $result['errorParams']];
    }

    /** Fills every XLIFF target with "FR " + source (inline codes kept). */
    private static function translateXliff(string $xliff): string
    {
        return (string) preg_replace_callback(
            '~<source>(.*?)</source>~s',
            static fn (array $m) => '<source>'.$m[1].'</source><target>FR '.$m[1].'</target>',
            $xliff,
        );
    }
}

/**
 * Page 12 ("About us") in the English root, with the target roots 3 (fr-CH) and 4 (de-LI).
 */
final class FakePages extends PageTranslator
{
    public string $title = 'About us';

    public bool $empty = false;

    public bool $extra = false;

    public string|null $failWith = null;

    /** @var list<array{pageId: int, rootId: int, translations: array<string, string>}> */
    public array $written = [];

    public function __construct()
    {
    }

    public function doc(): SegmentDocument
    {
        $doc = new SegmentDocument();

        if ($this->empty) {
            return $doc;
        }

        $doc->add('p.12.title', FieldCodec::textToHtml($this->title));
        $doc->add('c.20.text', '<p>Read <a href="{{link_url::5}}">more</a>{{br}}</p>');
        $doc->add('c.21.listitems.0', 'One');

        if ($this->extra) {
            $doc->add('c.22.text', '<p>New</p>');
        }

        return $doc;
    }

    public function targetRoots(int $pageId): array
    {
        return [
            ['id' => 3, 'title' => 'Français', 'language' => 'fr-CH', 'supertextLanguage' => '', 'targetPageId' => 77],
            ['id' => 4, 'title' => 'Liechtenstein', 'language' => 'de-LI', 'supertextLanguage' => '', 'targetPageId' => null],
        ];
    }

    public function segments(int $pageId): SegmentDocument|null
    {
        return 12 === $pageId ? $this->doc() : null;
    }

    public function pageTitle(int $pageId): string|null
    {
        return 12 === $pageId ? $this->title : null;
    }

    public function sourceLanguage(int $pageId): string|null
    {
        return 'en';
    }

    public function sourceSupertextLanguage(int $pageId): string
    {
        return '';
    }

    public function writeImported(int $pageId, int $targetRootId, array $translations): TranslationReport
    {
        $this->written[] = ['pageId' => $pageId, 'rootId' => $targetRootId, 'translations' => $translations];
        $report = new TranslationReport();
        $report->start(['id' => $targetRootId, 'title' => 'Français', 'language' => 'fr-CH']);

        if (null !== $this->failWith) {
            $report->fail($targetRootId, 'failed', $this->failWith, ['About us']);
        } else {
            $report->targets[$targetRootId]['pages'][] = ['id' => 77, 'title' => 'À propos', 'created' => false];
        }

        return $report;
    }
}
