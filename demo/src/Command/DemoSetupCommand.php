<?php

declare(strict_types=1);

namespace App\Command;

use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Makes the demo usable right after deployment. Runs on every start and is idempotent:
 *
 *  - Accounts from DEMO_ADMIN_EMAIL/PASSWORD (administrator) and DEMO_EDITOR_EMAIL/
 *    PASSWORD (editor in the "Editors" group, allowed to translate). Missing accounts
 *    are created; existing ones are never changed. Log in with the e-mail address.
 *  - On an empty database: theme, page layout, website roots for en (fallback), de-CH,
 *    fr-CH, it-CH, and English sample pages with articles and content elements.
 */
#[AsCommand(name: 'supertext:demo:setup', description: 'Creates the demo accounts, languages and sample content (idempotent).')]
final class DemoSetupCommand extends Command
{
    private const LANGUAGES = [
        ['en', 'English', 'en', true],
        ['de-CH', 'Deutsch', 'de', false],
        ['fr-CH', 'Français', 'fr', false],
        ['it-CH', 'Italiano', 'it', false],
    ];

    private SymfonyStyle $io;

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->framework->initialize();

        $groupId = $this->ensureEditorGroup();

        $this->ensureUser('DEMO_ADMIN', true, null);
        $this->ensureUser('DEMO_EDITOR', false, $groupId);

        if (false === $this->db->fetchOne("SELECT id FROM tl_page WHERE type = 'root' LIMIT 1")) {
            $this->createSite($groupId);
        } else {
            $this->io->writeln('Website roots exist, sample content left as is.');
        }

        return Command::SUCCESS;
    }

    /** @return bool whether the variables were set */
    private function ensureUser(string $prefix, bool $admin, int|null $groupId, bool $quiet = false): bool
    {
        $email = trim((string) ($_SERVER[$prefix.'_EMAIL'] ?? getenv($prefix.'_EMAIL') ?: ''));
        $password = (string) ($_SERVER[$prefix.'_PASSWORD'] ?? getenv($prefix.'_PASSWORD') ?: '');

        if ('' === $email || '' === $password) {
            if (!$quiet) {
                $this->io->writeln(\sprintf('%s_EMAIL / %s_PASSWORD not set, no account created.', $prefix, $prefix));
            }

            return false;
        }

        if (false !== $this->db->fetchOne('SELECT id FROM tl_user WHERE username = ? OR email = ?', [$email, $email])) {
            $this->io->writeln(\sprintf('Account from %s_EMAIL exists, left unchanged.', $prefix));

            return true;
        }

        $minLength = (int) ($GLOBALS['TL_CONFIG']['minPasswordLength'] ?? 8);

        if (mb_strlen($password) < $minLength) {
            $this->io->warning(\sprintf('%s_PASSWORD is shorter than Contao\'s minimum of %d characters; account not created.', $prefix, $minLength));

            return true;
        }

        $this->insert('tl_user', [
            'username' => $email,
            'name' => $admin ? 'Demo Administrator' : 'Demo Editor',
            'email' => $email,
            'password' => $this->hashers->getPasswordHasher(BackendUser::class)->hash($password),
            'admin' => $admin ? 1 : 0,
            'language' => 'en',
            'groups' => $groupId ? serialize([(string) $groupId]) : null,
            'inherit' => 'group',
            'pwChange' => 0,
            'dateAdded' => time(),
        ]);

        $this->io->writeln(\sprintf('Created %s account from %s_EMAIL.', $admin ? 'administrator' : 'editor', $prefix));

        return true;
    }

    private function ensureEditorGroup(): int
    {
        $id = $this->db->fetchOne('SELECT id FROM tl_user_group WHERE name = ?', ['Editors']);

        if (false !== $id) {
            return (int) $id;
        }

        $controller = $this->framework->getAdapter(Controller::class);
        $alexf = [];

        foreach (['tl_page', 'tl_article', 'tl_content'] as $table) {
            $controller->loadDataContainer($table);

            foreach ($GLOBALS['TL_DCA'][$table]['fields'] ?? [] as $field => $config) {
                if (!empty($config['exclude'])) {
                    $alexf[] = $table.'::'.$field;
                }
            }
        }

        $elements = [];

        foreach ($GLOBALS['TL_CTE'] ?? [] as $group) {
            $elements = [...$elements, ...array_keys($group)];
        }

        $id = $this->insert('tl_user_group', [
            'name' => 'Editors',
            'modules' => serialize(['page', 'article']),
            'pagemounts' => serialize([]),
            'alpty' => serialize(['regular', 'forward', 'redirect', 'root', 'error_401', 'error_403', 'error_404', 'error_503', 'logout']),
            'elements' => serialize(array_values(array_unique($elements))),
            'alexf' => serialize($alexf),
            'supertext' => 1,
        ]);

        $this->io->writeln('Created the "Editors" group (pages, articles, Supertext translation).');

        return $id;
    }

    private function createSite(int $groupId): void
    {
        $themeId = $this->insert('tl_theme', ['name' => 'Supertext demo', 'author' => 'Supertext']);

        $nav = $this->insert('tl_module', [
            'pid' => $themeId, 'name' => 'Navigation', 'type' => 'navigation', 'levelOffset' => 0, 'showLevel' => 1,
        ]);
        $languages = $this->insert('tl_module', [
            'pid' => $themeId, 'name' => 'Language switcher', 'type' => 'changelanguage', 'hideActiveLanguage' => 0, 'hideNoFallback' => 1,
        ]);

        $layoutId = $this->insert('tl_layout', [
            'pid' => $themeId,
            'name' => 'Default',
            'type' => 'default',
            'rows' => '2rwh',
            'cols' => '1cl',
            'template' => 'fe_page',
            'viewport' => 'width=device-width,initial-scale=1.0',
            'modules' => serialize([
                ['mod' => (string) $languages, 'col' => 'header', 'enable' => '1'],
                ['mod' => (string) $nav, 'col' => 'header', 'enable' => '1'],
                ['mod' => '0', 'col' => 'main', 'enable' => '1'],
            ]),
            'head' => '<style>'.self::CSS.'</style>',
        ]);

        $chmod = serialize(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'g1', 'g2', 'g3', 'g4', 'g5', 'g6']);
        $roots = [];
        $sorting = 128;

        foreach (self::LANGUAGES as [$language, $title, $prefix, $fallback]) {
            $roots[$language] = $this->insert('tl_page', [
                'pid' => 0, 'sorting' => $sorting, 'type' => 'root', 'title' => $title, 'alias' => $prefix,
                'language' => $language, 'fallback' => $fallback ? 1 : 0, 'dns' => '', 'urlPrefix' => $prefix,
                'urlSuffix' => '', 'includeLayout' => 1, 'layout' => $layoutId, 'published' => 1,
                'includeChmod' => 1, 'cgroup' => $groupId, 'chmod' => $chmod,
            ]);
            $sorting += 128;
        }

        $this->db->update('tl_user_group', ['pagemounts' => serialize(array_map('strval', array_values($roots)))], ['id' => $groupId]);

        $en = $roots['en'];
        $page = fn (int $pid, string $title, string $alias, int $sorting, string $description) => $this->insert('tl_page', [
            'pid' => $pid, 'sorting' => $sorting, 'type' => 'regular', 'title' => $title, 'alias' => $alias,
            'pageTitle' => $title.' – Supertext demo', 'description' => $description, 'published' => 1,
        ]);
        $article = fn (int $pid, string $title) => $this->insert('tl_article', [
            'pid' => $pid, 'sorting' => 128, 'title' => $title, 'alias' => $this->uniqueArticleAlias($title), 'inColumn' => 'main', 'published' => 1,
        ]);

        $home = $page($en, 'Home', 'home', 128, 'Content that speaks every language: a Contao site translated with Supertext.');
        $about = $page($en, 'About us', 'about-us', 256, 'Who we are and how we work.');
        $team = $page($about, 'Our team', 'our-team', 128, 'The people behind the demo.');
        $services = $page($en, 'Services', 'services', 384, 'What we offer.');

        $a = $article($home, 'Welcome');
        $this->element($a, 128, 'headline', ['headline' => serialize(['unit' => 'h1', 'value' => 'Content that speaks every language'])]);
        $this->element($a, 256, 'text', [
            'headline' => serialize(['unit' => 'h2', 'value' => 'Translate your whole site in minutes']),
            'text' => '<p>This Contao site was written in <strong>English</strong>. Open the site structure, click <em>Translate with Supertext</em> on any page and choose German, French or Italian.</p><p>Formatting, <a href="{{link_url::'.$about.'}}">links to other pages</a> and insert tags such as {{date::Y}} survive the translation.</p>',
        ]);
        $this->element($a, 384, 'list', [
            'headline' => serialize(['unit' => 'h2', 'value' => 'What gets translated']),
            'listtype' => 'unordered',
            'listitems' => serialize(['Page titles, descriptions and URLs', 'Articles and their teasers', 'Text, headlines, lists and tables', '<strong>Nested</strong> content elements']),
        ]);

        $a = $article($about, 'About us');
        $this->element($a, 128, 'text', [
            'headline' => serialize(['unit' => 'h1', 'value' => 'About us']),
            'text' => '<p>We are a Swiss language company. Our team combines AI translation with linguists who know your market &amp; your customers.</p>',
        ]);
        $this->element($a, 256, 'table', [
            'headline' => serialize(['unit' => 'h2', 'value' => 'Our numbers']),
            'thead' => 1,
            'tableitems' => serialize([['Languages', 'Clients', 'Founded'], ['More than 100', 'Over 4,000', '2005']]),
            'summary' => 'Key facts about the company',
        ]);
        $group = $this->element($a, 384, 'element_group', [], 'tl_article');
        if ($group) {
            $this->element($group, 128, 'text', ['text' => '<p>Every translation can be <strong>reviewed</strong> before it goes live: new pages are created unpublished.</p>'], 'tl_content');
        }

        $a = $article($team, 'Our team');
        $this->element($a, 128, 'text', [
            'headline' => serialize(['unit' => 'h1', 'value' => 'Our team']),
            'text' => '<p>Translators, engineers and project managers in Zurich, Berlin and Lisbon.</p>',
        ]);

        $a = $article($services, 'Services');
        $this->element($a, 128, 'text', [
            'headline' => serialize(['unit' => 'h1', 'value' => 'Services']),
            'text' => '<p>AI translation, human review, copywriting and localisation for websites, apps and documents.</p>',
        ]);
        $this->element($a, 256, 'hyperlink', [
            'url' => 'https://www.supertext.com',
            'linkTitle' => 'Visit supertext.com',
            'titleText' => 'Supertext website',
            'target' => 1,
        ]);

        $this->io->success('Created the demo site: English sample pages and website roots for de-CH, fr-CH and it-CH.');
    }

    /** Inserts a content element if the element type exists in this Contao version. */
    private function element(int $pid, int $sorting, string $type, array $fields, string $ptable = 'tl_article'): int|null
    {
        $known = false;

        foreach ($GLOBALS['TL_CTE'] ?? [] as $group) {
            $known = $known || isset($group[$type]);
        }

        if (!$known) {
            return null;
        }

        return $this->insert('tl_content', ['pid' => $pid, 'ptable' => $ptable, 'sorting' => $sorting, 'type' => $type, 'invisible' => 0, ...$fields]);
    }

    private function uniqueArticleAlias(string $title): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        $alias = $base;

        for ($i = 2; false !== $this->db->fetchOne('SELECT id FROM tl_article WHERE alias = ?', [$alias]); ++$i) {
            $alias = $base.'-'.$i;
        }

        return $alias;
    }

    /** Inserts only the columns the table has (they differ between Contao versions). */
    private function insert(string $table, array $row): int
    {
        static $columns = [];
        $columns[$table] ??= array_map(static fn ($c) => $c->getName(), $this->db->createSchemaManager()->listTableColumns($table));
        $row = array_intersect_key(['tstamp' => time(), ...$row], array_flip($columns[$table]));

        $quoted = [];

        foreach ($row as $key => $value) {
            $quoted[$this->db->quoteSingleIdentifier($key)] = $value;
        }

        $this->db->insert($table, $quoted);

        return (int) $this->db->lastInsertId();
    }

    private const CSS = <<<'CSS'
        body{margin:0;font:17px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1d1d1b;background:#fbfbf8}
        #wrapper{max-width:760px;margin:0 auto;padding:24px 16px 64px}
        #header{display:flex;flex-wrap:wrap;gap:8px 24px;align-items:center;justify-content:space-between;border-bottom:1px solid #e4e4de;padding-bottom:12px;margin-bottom:24px}
        #header ul{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:4px 16px}
        a{color:inherit;text-decoration-color:#00b386;text-underline-offset:3px}
        .active,strong.active{color:#00b386}
        h1{font-size:2rem;line-height:1.2}
        table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:6px 4px;border-bottom:1px solid #e4e4de}
        .invisible{position:absolute;left:-9999px}
        CSS;
}
