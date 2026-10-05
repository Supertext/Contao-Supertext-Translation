<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Integration;

use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Tests\FakeSupertext;
use Supertext\ContaoTranslation\Translation\ContaoHooks;
use Supertext\ContaoTranslation\Translation\FieldMap;
use Supertext\ContaoTranslation\Translation\Languages;
use Supertext\ContaoTranslation\Translation\PageTranslator;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Runs against a real Contao installation with this bundle installed and a migrated
 * database. Set CONTAO_PROJECT_DIR and CONTAO_TEST_DATABASE_URL (all tables of that
 * database are emptied). See docs/DEVELOPER.md.
 */
final class PageTranslatorTest extends TestCase
{
    private static ContaoKernel|null $kernel = null;

    private Connection $db;

    private FakeSupertext $fake;

    public static function setUpBeforeClass(): void
    {
        $projectDir = getenv('CONTAO_PROJECT_DIR') ?: '';
        $databaseUrl = getenv('CONTAO_TEST_DATABASE_URL') ?: '';

        if ('' === $projectDir || '' === $databaseUrl) {
            self::markTestSkipped('Set CONTAO_PROJECT_DIR and CONTAO_TEST_DATABASE_URL to run the integration tests.');
        }

        (new Dotenv())->usePutenv(false)->bootEnv($projectDir.'/.env');
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $databaseUrl;
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'prod';

        ContaoKernel::setProjectDir($projectDir);
        self::$kernel = new ContaoKernel('prod', false);
        self::$kernel->boot();
        self::$kernel->getContainer()->get('contao.framework')->initialize();
    }

    protected function setUp(): void
    {
        $container = self::$kernel->getContainer();
        $this->db = $container->get('database_connection');

        foreach (['tl_page', 'tl_article', 'tl_content', 'tl_version'] as $table) {
            $this->db->executeStatement("DELETE FROM $table");
        }

        $this->fake = new FakeSupertext();
    }

    private function translator(FakeSupertext|null $fake = null, array $fieldOverrides = []): PageTranslator
    {
        $container = self::$kernel->getContainer();
        $client = new SupertextClient(($fake ?? $this->fake)->client(), 'test-key', 'https://api.test/v1', 1000, 10_000, static function (): void {});
        $hooks = new ContaoHooks($container->get('contao.framework'), $this->db, $container->get('contao.slug'));

        return new PageTranslator($this->db, $client, new FieldMap($fieldOverrides), new Languages([], ['de-CH' => 'more']), $hooks);
    }

    private function insert(string $table, array $row): int
    {
        $row += ['tstamp' => time()];
        $quoted = [];

        foreach ($row as $k => $v) {
            $quoted[$this->db->quoteIdentifier($k)] = $v;
        }

        $this->db->insert($table, $quoted);

        return (int) $this->db->lastInsertId();
    }

    private function row(string $table, int $id): array
    {
        return $this->db->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$id]) ?: [];
    }

    /** @return array{en: int, de: int, fr: int, home: int, about: int, team: int, article: int, text: int, list: int, group: int, nested: int} */
    private function site(): array
    {
        $root = fn (string $title, string $lang, bool $fallback, int $sorting) => $this->insert('tl_page', [
            'pid' => 0, 'sorting' => $sorting, 'title' => $title, 'alias' => strtolower($title), 'type' => 'root',
            'language' => $lang, 'fallback' => $fallback ? 1 : 0, 'dns' => '', 'published' => 1,
            'urlPrefix' => 'en' === $lang ? '' : strtolower($lang),
        ]);

        $s['en'] = $root('English', 'en', true, 128);
        $s['de'] = $root('Deutsch', 'de-CH', false, 256);
        $s['fr'] = $root('Français', 'fr-CH', false, 384);

        $page = fn (int $pid, string $title, string $alias, int $sorting) => $this->insert('tl_page', [
            'pid' => $pid, 'sorting' => $sorting, 'title' => $title, 'alias' => $alias, 'type' => 'regular',
            'published' => 1, 'pageTitle' => $title.' | Demo', 'description' => 'About "us" & <friends>',
        ]);

        $s['home'] = $page($s['en'], 'Home', 'home', 128);
        $s['about'] = $page($s['en'], 'About & us', 'about', 256);
        $s['team'] = $page($s['about'], 'Our team', 'team', 128);

        $s['article'] = $this->insert('tl_article', [
            'pid' => $s['about'], 'sorting' => 128, 'title' => 'About', 'alias' => 'about-article', 'inColumn' => 'main',
            'published' => 1, 'teaser' => '<p>Short teaser</p>',
        ]);

        $element = fn (array $r) => $this->insert('tl_content', $r + ['ptable' => 'tl_article', 'pid' => $s['article'], 'invisible' => 0]);
        $s['text'] = $element([
            'type' => 'text', 'sorting' => 128,
            'headline' => serialize(['unit' => 'h2', 'value' => 'Who we are']),
            'text' => '<p>We <strong>translate</strong> <a href="{{link_url::'.$s['home'].'}}">things</a>.{{br}}Grüezi</p>',
        ]);
        $s['list'] = $element(['type' => 'list', 'sorting' => 256, 'listitems' => serialize(['One', 'Two'])]);
        $s['group'] = $element(['type' => 'element_group', 'sorting' => 384]);
        $s['nested'] = $this->insert('tl_content', [
            'ptable' => 'tl_content', 'pid' => $s['group'], 'type' => 'text', 'sorting' => 128, 'invisible' => 0, 'text' => '<p>Nested</p>',
        ]);

        return $s;
    }

    public function testTranslatesPageArticlesAndElementsIntoEachRoot(): void
    {
        $s = $this->site();
        $report = $this->translator()->translatePage($s['about'], [$s['de'], $s['fr']]);

        $this->assertFalse($report->hasErrors(), json_encode($report->targets));
        $this->assertCount(2, $this->fake->calls(fn ($c) => 'POST' === $c['method']));

        $files = array_values(array_map(static fn ($f) => [$f['target'], $f['source'], $f['politeness'], $f['type']], $this->fake->submitted));
        $this->assertSame([['de-CH', 'en', 'more', 'text/html'], ['fr-CH', 'en', '', 'text/html']], $files);

        $dePage = $report->targets[$s['de']]['pages'][0];
        $this->assertTrue($dePage['created']);

        $de = $this->row('tl_page', $dePage['id']);
        $this->assertSame($s['de'], (int) $de['pid']);
        $this->assertSame(0, (int) $de['published'], 'new translations are unpublished');
        $this->assertSame($s['about'], (int) $de['supertext_source']);
        $this->assertSame($s['about'], (int) $de['languageMain'], 'linked for the language switcher');
        $this->assertSame('[de-CH] About & us', html_entity_decode($de['title']));
        $this->assertStringStartsWith('de-ch-about', $de['alias']);
        $this->assertStringContainsString('[de-CH] About', html_entity_decode($de['description']));

        $article = $this->db->fetchAssociative('SELECT * FROM tl_article WHERE pid = ?', [$dePage['id']]);
        $this->assertSame('<p>[de-CH] Short teaser</p>', $article['teaser']);
        $this->assertSame('main', $article['inColumn']);

        $text = $this->db->fetchAssociative("SELECT * FROM tl_content WHERE ptable = 'tl_article' AND pid = ? AND type = 'text'", [$article['id']]);
        $this->assertSame('[de-CH] Who we are', unserialize($text['headline'])['value']);
        $this->assertSame(
            '<p>[de-CH] We <strong>translate</strong> <a href="{{link_url::'.$s['home'].'}}">things</a>.{{br}}Grüezi</p>',
            $text['text'],
            'markup, insert tags and umlauts survive',
        );

        $list = $this->db->fetchAssociative("SELECT * FROM tl_content WHERE pid = ? AND type = 'list'", [$article['id']]);
        $this->assertSame(['[de-CH] One', '[de-CH] Two'], unserialize($list['listitems']));

        $group = $this->db->fetchAssociative("SELECT * FROM tl_content WHERE pid = ? AND type = 'element_group'", [$article['id']]);
        $nested = $this->db->fetchAssociative("SELECT * FROM tl_content WHERE ptable = 'tl_content' AND pid = ?", [$group['id']]);
        $this->assertSame('<p>[de-CH] Nested</p>', $nested['text'], 'nested elements are translated');

        // Source untouched
        $this->assertSame('About & us', $this->row('tl_page', $s['about'])['title']);
    }

    public function testRetranslatingUpdatesInPlaceAndHidesRemovedElements(): void
    {
        $s = $this->site();
        $first = $this->translator()->translatePage($s['about'], [$s['de']]);
        $dePageId = $first->targets[$s['de']]['pages'][0]['id'];

        // Editor publishes the translation, then the source changes.
        $this->db->update('tl_page', ['published' => 1], ['id' => $dePageId]);
        $this->db->update('tl_content', ['text' => '<p>Changed</p>'], ['id' => $s['nested']]);
        $this->db->delete('tl_content', ['id' => $s['list']]);

        $second = $this->translator(new FakeSupertext())->translatePage($s['about'], [$s['de']]);

        $this->assertFalse($second->hasErrors());
        $this->assertFalse($second->targets[$s['de']]['pages'][0]['created']);
        $this->assertSame($dePageId, $second->targets[$s['de']]['pages'][0]['id']);
        $this->assertSame(1, $second->targets[$s['de']]['hidden']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_page WHERE pid = ?', [$s['de']]), 'no duplicate page');
        $this->assertSame('1', (string) $this->row('tl_page', $dePageId)['published'], 'publish state kept');

        $article = (int) $this->db->fetchOne('SELECT id FROM tl_article WHERE pid = ?', [$dePageId]);
        $this->assertSame('1', (string) $this->db->fetchOne("SELECT invisible FROM tl_content WHERE pid = ? AND type = 'list'", [$article]), 'removed source element is hidden');
        $this->assertSame('<p>[de-CH] Changed</p>', $this->db->fetchOne("SELECT text FROM tl_content WHERE ptable = 'tl_content' AND supertext_source = ?", [$s['nested']]));
    }

    public function testCreatesMissingParentsAndSubpages(): void
    {
        $s = $this->site();
        $report = $this->translator()->translatePage($s['team'], [$s['fr']]);

        $this->assertFalse($report->hasErrors(), json_encode($report->targets));
        $titles = array_map(static fn ($p) => $p['title'], $report->targets[$s['fr']]['pages']);
        $this->assertSame(['[fr-CH] About & us', '[fr-CH] Our team'], $titles, 'parent first');

        $about = $report->targets[$s['fr']]['pages'][0]['id'];
        $team = $report->targets[$s['fr']]['pages'][1]['id'];
        $this->assertSame($about, (int) $this->row('tl_page', $team)['pid']);

        // Whole tree from the root
        $all = $this->translator(new FakeSupertext())->translatePage($s['en'], [$s['de']]);
        $this->assertSame(3, \count($all->targets[$s['de']]['pages']));
    }

    public function testOnlyOffersLanguageRootsOfTheSameSite(): void
    {
        $s = $this->site();
        $this->insert('tl_page', ['pid' => 0, 'title' => 'Other site', 'type' => 'root', 'language' => 'it', 'dns' => 'other.example', 'published' => 1]);

        $roots = $this->translator()->targetRoots($s['about']);
        $this->assertSame(['de-CH', 'fr-CH'], array_column($roots, 'language'));
        $this->assertSame([null, null], array_column($roots, 'targetPageId'));

        $this->expectException(\InvalidArgumentException::class);
        $this->translator()->translatePage($s['about'], [$s['en']]);
    }

    public function testReportsSupertextErrorsPerLanguage(): void
    {
        $s = $this->site();
        $report = $this->translator(new FakeSupertext(['limit_exceeded']))->translatePage($s['about'], [$s['de']]);

        $this->assertTrue($report->hasErrors());
        $this->assertSame('quota_exceeded', $report->targets[$s['de']]['errorCode']);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_page WHERE pid = ?', [$s['de']]), 'nothing written');
    }

    public function testCustomFieldsCanBeAddedAndDefaultsSwitchedOff(): void
    {
        $s = $this->site();
        $report = $this->translator(null, ['tl_page' => ['description' => false], 'tl_article' => ['teaser' => false]])->translatePage($s['about'], [$s['de']]);
        $de = $this->row('tl_page', $report->targets[$s['de']]['pages'][0]['id']);

        $this->assertStringNotContainsString('[de-CH]', $de['description']);
        $this->assertSame('<p>Short teaser</p>', $this->db->fetchOne('SELECT teaser FROM tl_article WHERE pid = ?', [$de['id']]));
    }
}
