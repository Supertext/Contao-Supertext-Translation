<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Doctrine\DBAL\Connection;
use Supertext\ContaoTranslation\Html\FieldCodec;
use Supertext\ContaoTranslation\Html\SegmentDocument;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Supertext\SupertextException;

/**
 * Mirrors a page of one website root into the other language roots and translates it.
 *
 * Contao keeps one site tree per language. A page, its articles and their content
 * elements (including nested elements) are copied into each target root and their
 * text fields translated. Copies remember their source in `supertext_source`, so
 * translating again updates the same records instead of creating new ones.
 *
 *  - New pages are created unpublished, so an editor reviews them before going live.
 *  - Existing translations are updated in place; the previous state stays in Contao's
 *    version history.
 *  - Articles and elements removed from the source are hidden (never deleted) in the
 *    target. Records added by hand in the target are never touched.
 *  - If the parent page has no translation yet, it is translated first.
 *  - With terminal42/contao-changelanguage installed, new pages are linked to their
 *    source via `languageMain`, so the language switcher works.
 */
class PageTranslator
{
    /** Columns never copied from the source on create/update. */
    private const SKIP = ['id', 'pid', 'ptable', 'tstamp', 'alias', 'supertext_source', 'languageMain'];

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var array<string, string> */
    private array $encodings = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly SupertextClient $client,
        private readonly FieldMap $fields,
        private readonly Languages $languages,
        private readonly ContaoHooks $hooks,
    ) {
    }

    /**
     * Other website roots of the same site (same domain), in a different language.
     *
     * @return list<array{id: int, title: string, language: string, targetPageId: int|null}>
     */
    public function targetRoots(int $pageId): array
    {
        $root = $this->rootOf($pageId);

        if (null === $root) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, title, language FROM tl_page WHERE type = 'root' AND id != ? AND dns = ? AND language != ? ORDER BY sorting",
            [$root['id'], $root['dns'], $root['language']],
        );

        return array_map(fn (array $r) => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'language' => (string) $r['language'],
            'targetPageId' => $this->findTargetPage($pageId, (int) $r['id']),
        ], $rows);
    }

    /**
     * @param list<int> $targetRootIds
     */
    public function translatePage(int $pageId, array $targetRootIds, bool $includeSubpages = false): TranslationReport
    {
        $report = new TranslationReport();
        $source = $this->page($pageId) ?? throw new \InvalidArgumentException(\sprintf('Page %d does not exist.', $pageId));
        $sourceRoot = $this->rootOf($pageId) ?? throw new \InvalidArgumentException(\sprintf('Page %d is not inside a website root.', $pageId));
        $allowed = array_column($this->targetRoots($pageId), null, 'id');

        // A website root has no content of its own: translating it means its pages.
        $includeSubpages = $includeSubpages || 'root' === $source['type'];
        $pages = $includeSubpages ? [$pageId, ...$this->descendants($pageId)] : [$pageId];

        foreach ($targetRootIds as $rootId) {
            if (!isset($allowed[$rootId])) {
                throw new \InvalidArgumentException(\sprintf('Page %d is not a language root of this website.', $rootId));
            }

            $report->start($allowed[$rootId]);
        }

        // Ancestors without a translation in some target are translated first (top-down).
        $queue = [];

        foreach ($pages as $id) {
            foreach ($this->ancestors($id, (int) $sourceRoot['id']) as $ancestor) {
                foreach ($targetRootIds as $rootId) {
                    if (null === $this->findTargetPage($ancestor, $rootId)) {
                        $queue[$ancestor] = true;
                    }
                }
            }

            $queue[$id] = true;
        }

        unset($source);

        foreach (array_keys($queue) as $id) {
            $this->translateOne($id, $sourceRoot, $targetRootIds, $report);
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $sourceRoot
     * @param list<int>            $targetRootIds
     */
    private function translateOne(int $pageId, array $sourceRoot, array $targetRootIds, TranslationReport $report): void
    {
        $page = $this->page($pageId);

        if (null === $page) {
            return;
        }

        $isRoot = 'root' === $page['type'];
        $articles = $isRoot ? [] : $this->connection->fetchAllAssociative('SELECT * FROM tl_article WHERE pid = ? ORDER BY sorting', [$pageId]);
        $elements = [];

        foreach ($articles as $article) {
            $elements = [...$elements, ...$this->elementsOf('tl_article', (int) $article['id'])];
        }

        // One document for the page and everything on it.
        $doc = new SegmentDocument();

        if (!$isRoot) {
            $this->addRecord($doc, 'tl_page', 'p', $page);
        }

        foreach ($articles as $article) {
            $this->addRecord($doc, 'tl_article', 'a', $article);
        }

        foreach ($elements as $element) {
            $this->addRecord($doc, 'tl_content', 'c', $element);
        }

        // Submit for every target first, so Supertext works on all languages in parallel.
        $jobs = [];

        foreach ($targetRootIds as $rootId) {
            if (!$report->targets[$rootId]['ok']) {
                continue;
            }

            $root = $this->page($rootId);

            if ($doc->isEmpty()) {
                $jobs[$rootId] = null;
                continue;
            }

            try {
                $jobs[$rootId] = $this->client->submit(
                    $doc->toHtml(),
                    $this->languages->target((string) $root['language']),
                    $this->languages->source((string) $sourceRoot['language']),
                    $this->languages->politeness((string) $root['language']),
                );
            } catch (SupertextException $e) {
                $report->fail($rootId, $e->getMessage(), $e->errorCode, [(string) $e->httpStatus], $e->detail);
            }
        }

        foreach ($jobs as $rootId => $fileId) {
            try {
                $translations = [];

                if (null !== $fileId) {
                    $parsed = $doc->parse($this->client->collect($fileId));
                    $translations = $parsed['translations'];
                    $report->targets[$rootId]['missing'] = [...$report->targets[$rootId]['missing'], ...$parsed['missing']];

                    foreach ($parsed['lostPlaceholders'] as $key) {
                        $report->warn($rootId, 'MSC.supertext.insertTagsMoved', [$key]);
                    }
                }

                $this->connection->transactional(
                    fn () => $this->write($page, $articles, $elements, $sourceRoot, $rootId, $translations, $report),
                );
            } catch (SupertextException $e) {
                $report->fail($rootId, $e->getMessage(), $e->errorCode, [(string) $e->httpStatus], $e->detail);
            } catch (TranslationException $e) {
                $report->fail($rootId, $e->getMessage(), $e->errorCode, $e->params);
            } catch (\Throwable $e) {
                $report->fail($rootId, $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed>       $page
     * @param list<array<string, mixed>> $articles
     * @param list<array<string, mixed>> $elements
     * @param array<string, mixed>       $sourceRoot
     * @param array<string, string>      $tr
     */
    private function write(array $page, array $articles, array $elements, array $sourceRoot, int $rootId, array $tr, TranslationReport $report): void
    {
        $pageId = (int) $page['id'];
        $tags = [];

        if ('root' === $page['type']) {
            $targetPageId = $rootId;
        } else {
            $targetPageId = $this->findTargetPage($pageId, $rootId);
            $created = null === $targetPageId;

            if ($created) {
                $parent = $this->findTargetPage((int) $page['pid'], $rootId)
                    ?? throw new TranslationException('parent_not_translated', \sprintf('The parent of page "%s" has no translation.', $page['title']), [(string) $page['title']]);

                $row = $this->translatedRow('tl_page', 'p', $page, $tr);
                $row['pid'] = $parent;
                $row['published'] = 0;
                $row['alias'] = '';
                $row['supertext_source'] = $pageId;

                if ($this->hasColumn('tl_page', 'languageMain')) {
                    $row['languageMain'] = (int) $sourceRoot['fallback'] ? $pageId : (int) ($page['languageMain'] ?? 0);
                }

                $targetPageId = $this->insert('tl_page', $row);
                $this->connection->update('tl_page', ['alias' => $this->hooks->pageAlias($targetPageId, (string) $row['title'])], ['id' => $targetPageId]);
            } else {
                $this->update('tl_page', $targetPageId, $this->translatedFields('tl_page', 'p', $page, $tr));
            }

            $report->targets[$rootId]['pages'][] = [
                'id' => $targetPageId,
                'title' => html_entity_decode((string) ($this->connection->fetchOne('SELECT title FROM tl_page WHERE id = ?', [$targetPageId]) ?: ''), ENT_QUOTES | ENT_HTML5),
                'created' => $created,
            ];
            $tags[] = 'contao.db.tl_page.'.$targetPageId;
        }

        // Articles
        $articleMap = [];

        foreach ($articles as $article) {
            $sourceId = (int) $article['id'];
            $targetId = $this->findChild('tl_article', $targetPageId, null, $sourceId);
            $row = $this->translatedRow('tl_article', 'a', $article, $tr);
            $row['pid'] = $targetPageId;

            if (null === $targetId) {
                $row['supertext_source'] = $sourceId;
                $row['alias'] = $this->hooks->articleAlias($targetPageId, (string) $row['title']);
                $targetId = $this->insert('tl_article', $row);
            } else {
                unset($row['published']);
                $this->update('tl_article', $targetId, $row);
            }

            $articleMap[$sourceId] = $targetId;
            ++$report->targets[$rootId]['articles'];
            $tags[] = 'contao.db.tl_article.'.$targetId;
        }

        $report->targets[$rootId]['hidden'] += $this->hideOrphans('tl_article', 'published', 0, $targetPageId, null, array_keys($articleMap));

        // Content elements; parents always come before their children.
        $elementMap = [];
        $children = [];

        foreach ($elements as $element) {
            $sourceId = (int) $element['id'];
            $isNested = 'tl_content' === $element['ptable'];
            $targetParent = $isNested ? ($elementMap[(int) $element['pid']] ?? null) : ($articleMap[(int) $element['pid']] ?? null);

            if (null === $targetParent) {
                continue;
            }

            $targetId = $this->findChild('tl_content', $targetParent, $element['ptable'], $sourceId);
            $row = $this->translatedRow('tl_content', 'c', $element, $tr);
            $row['pid'] = $targetParent;
            $row['ptable'] = $element['ptable'];

            if (null === $targetId) {
                $row['supertext_source'] = $sourceId;
                $targetId = $this->insert('tl_content', $row);
            } else {
                unset($row['invisible']);
                $this->update('tl_content', $targetId, $row);
            }

            $elementMap[$sourceId] = $targetId;
            $children[$element['ptable'].'.'.$targetParent][] = $sourceId;
            ++$report->targets[$rootId]['elements'];
            $tags[] = 'contao.db.tl_content.'.$targetId;
        }

        foreach ($articleMap as $targetArticle) {
            $report->targets[$rootId]['hidden'] += $this->hideOrphans('tl_content', 'invisible', 1, $targetArticle, 'tl_article', $children['tl_article.'.$targetArticle] ?? []);
        }

        foreach ($elementMap as $targetElement) {
            $report->targets[$rootId]['hidden'] += $this->hideOrphans('tl_content', 'invisible', 1, $targetElement, 'tl_content', $children['tl_content.'.$targetElement] ?? []);
        }

        $this->hooks->invalidate($tags);
    }

    /** Page in the given target root that is the translation of $sourcePageId. */
    public function findTargetPage(int $sourcePageId, int $targetRootId): int|null
    {
        $source = $this->page($sourcePageId);

        if (null === $source) {
            return null;
        }

        if ('root' === $source['type']) {
            return $targetRootId;
        }

        $candidates = $this->connection->fetchFirstColumn('SELECT id FROM tl_page WHERE supertext_source = ?', [$sourcePageId]);

        if ($this->hasColumn('tl_page', 'languageMain')) {
            $main = (int) ($source['languageMain'] ?? 0) ?: $sourcePageId;
            $candidates = [
                ...$candidates,
                ...$this->connection->fetchFirstColumn('SELECT id FROM tl_page WHERE (languageMain = ? OR id = ?) AND id != ?', [$main, $main, $sourcePageId]),
            ];
        }

        foreach (array_unique(array_map('intval', $candidates)) as $id) {
            if ((int) ($this->rootOf($id)['id'] ?? 0) === $targetRootId) {
                return $id;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function rootOf(int $pageId): array|null
    {
        for ($i = 0; $i < 100 && $pageId > 0; ++$i) {
            $page = $this->page($pageId);

            if (null === $page) {
                return null;
            }

            if ('root' === $page['type']) {
                return $page;
            }

            $pageId = (int) $page['pid'];
        }

        return null;
    }

    /** @return list<int> ancestors below the root, top-down */
    private function ancestors(int $pageId, int $rootId): array
    {
        $out = [];
        $page = $this->page($pageId);

        while ($page && (int) $page['pid'] !== $rootId && (int) $page['pid'] > 0 && 'root' !== $page['type']) {
            $page = $this->page((int) $page['pid']);

            if (!$page || 'root' === $page['type']) {
                break;
            }

            array_unshift($out, (int) $page['id']);
        }

        return $out;
    }

    /** @return list<int> all subpages in tree order */
    private function descendants(int $pageId): array
    {
        $out = [];

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_page WHERE pid = ? ORDER BY sorting', [$pageId]) as $id) {
            $out[] = (int) $id;
            $out = [...$out, ...$this->descendants((int) $id)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> elements of a parent, each followed by its nested elements */
    private function elementsOf(string $ptable, int $pid): array
    {
        $out = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_content WHERE ptable = ? AND pid = ? ORDER BY sorting', [$ptable, $pid]) as $row) {
            $out[] = $row;
            $out = [...$out, ...$this->elementsOf('tl_content', (int) $row['id'])];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function page(int $id): array|null
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$id]) ?: null;
    }

    private function addRecord(SegmentDocument $doc, string $table, string $prefix, array $row): void
    {
        foreach ($this->fields->fieldsFor($table) as $field => $type) {
            if (!\array_key_exists($field, $row)) {
                continue;
            }

            foreach (FieldCodec::segments($type, $prefix.'.'.$row['id'].'.'.$field, $row[$field]) as $key => $html) {
                $doc->add($key, $html);
            }
        }
    }

    /** @return array<string, mixed> translated fields only */
    private function translatedFields(string $table, string $prefix, array $row, array $tr): array
    {
        $out = [];

        foreach ($this->fields->fieldsFor($table) as $field => $type) {
            if (\array_key_exists($field, $row)) {
                $out[$field] = FieldCodec::apply($type, $prefix.'.'.$row['id'].'.'.$field, $row[$field], $tr, $this->encoding($table, $field));
            }
        }

        return $out;
    }

    /** @return array<string, mixed> full copy of the source row with translated fields */
    private function translatedRow(string $table, string $prefix, array $row, array $tr): array
    {
        $copy = array_diff_key($row, array_flip(self::SKIP));
        $copy['tstamp'] = time();

        return [...$copy, ...$this->translatedFields($table, $prefix, $row, $tr)];
    }

    private function findChild(string $table, int $pid, string|null $ptable, int $sourceId): int|null
    {
        $sql = "SELECT id FROM $table WHERE pid = ? AND supertext_source = ?".(null !== $ptable ? ' AND ptable = ?' : '');
        $id = $this->connection->fetchOne($sql, null !== $ptable ? [$pid, $sourceId, $ptable] : [$pid, $sourceId]);

        return false === $id ? null : (int) $id;
    }

    /**
     * Hides target records whose source no longer exists under the same parent.
     *
     * @param list<int> $keepSourceIds
     */
    private function hideOrphans(string $table, string $flag, int $hiddenValue, int $pid, string|null $ptable, array $keepSourceIds): int
    {
        $sql = "SELECT id, supertext_source, $flag AS flag FROM $table WHERE pid = ? AND supertext_source > 0".(null !== $ptable ? ' AND ptable = ?' : '');
        $rows = $this->connection->fetchAllAssociative($sql, null !== $ptable ? [$pid, $ptable] : [$pid]);
        $hidden = 0;

        foreach ($rows as $row) {
            if (!\in_array((int) $row['supertext_source'], $keepSourceIds, true) && (int) $row['flag'] !== $hiddenValue) {
                $this->update($table, (int) $row['id'], [$flag => $hiddenValue]);
                ++$hidden;
            }
        }

        return $hidden;
    }

    private function insert(string $table, array $row): int
    {
        $row = array_intersect_key($row, array_flip($this->columnsOf($table)));
        $this->connection->insert($table, $this->quoteKeys($row));

        return (int) $this->connection->lastInsertId();
    }

    private function update(string $table, int $id, array $row): void
    {
        $row = array_intersect_key($row, array_flip($this->columnsOf($table)));
        unset($row['id']);

        if ([] === $row) {
            return;
        }

        $row['tstamp'] = time();
        $this->hooks->beforeUpdate($table, $id);
        $this->connection->update($table, $this->quoteKeys($row), ['id' => $id]);
        $this->hooks->afterUpdate($table, $id);
    }

    /** Some Contao columns are reserved words in MySQL (e.g. `rows`, `size`). */
    private function quoteKeys(array $row): array
    {
        $out = [];

        foreach ($row as $k => $v) {
            // DBAL 4.3+ has quoteSingleIdentifier(); DBAL 3 only quoteIdentifier().
            $out[method_exists($this->connection, 'quoteSingleIdentifier') ? $this->connection->quoteSingleIdentifier((string) $k) : $this->connection->quoteIdentifier((string) $k)] = $v;
        }

        return $out;
    }

    private function encoding(string $table, string $field): string
    {
        return $this->encodings[$table.'.'.$field] ??= $this->hooks->textEncoding($table, $field);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return \in_array($column, $this->columnsOf($table), true);
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        // Keys of listTableColumns() may be lower-cased; Contao columns are camelCase.
        return $this->columns[$table] ??= array_values(array_map(
            static fn ($column) => $column->getName(),
            $this->connection->createSchemaManager()->listTableColumns($table),
        ));
    }
}
