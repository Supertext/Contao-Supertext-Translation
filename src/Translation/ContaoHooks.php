<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Contao\Controller;
use Contao\CoreBundle\Cache\EntityCacheTags;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Slug\Slug;
use Contao\PageModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;

/**
 * The parts of a translation run that need the Contao framework: version history,
 * aliases and cache invalidation. Kept apart so the sync logic stays testable.
 */
class ContaoHooks
{
    /** @var array<string, Versions> */
    private array $pending = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly Slug $slug,
        private readonly EntityCacheTags|null $cacheTags = null,
    ) {
    }

    /**
     * How Contao stores plain text in this field (see FieldCodec::ENCODINGS):
     * Contao 6 stores text as typed; Contao 5 encodes special characters unless the
     * field has `decodeEntities`.
     */
    public function textEncoding(string $table, string $field): string
    {
        if (version_compare(ContaoCoreBundle::getVersion(), '6.0.0-dev', '>=')) {
            return 'raw';
        }

        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer($table);

        return !empty($GLOBALS['TL_DCA'][$table]['fields'][$field]['eval']['decodeEntities'])
            ? 'encodeLessThanSign'
            : 'encodeAll';
    }

    /** Call before updating an existing record so the change can be undone in "Versions". */
    public function beforeUpdate(string $table, int $id): void
    {
        try {
            $this->framework->initialize();
            $versions = new Versions($table, $id);
            $versions->initialize();
            $this->pending[$table.'.'.$id] = $versions;
        } catch (\Throwable) {
            // Versioning is a convenience; never fail a translation over it.
        }
    }

    public function afterUpdate(string $table, int $id): void
    {
        $versions = $this->pending[$table.'.'.$id] ?? null;
        unset($this->pending[$table.'.'.$id]);

        try {
            $versions?->create();
        } catch (\Throwable) {
        }
    }

    /** Alias for a newly created page, unique within its website root (and URL prefix). */
    public function pageAlias(int $pageId, string $title): string
    {
        $this->framework->initialize();
        $page = $this->framework->getAdapter(PageModel::class)->findWithDetails($pageId);

        if (null === $page) {
            return '';
        }

        $prefix = $page->useFolderUrl ? (string) $page->folderUrl : '';
        $alias = $this->slug->generate(
            html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $pageId,
            fn (string $alias): bool => $this->pageAliasExists($prefix.$alias, $page),
        );

        return $prefix.$alias;
    }

    /** Alias for a newly created article, unique across all articles. */
    public function articleAlias(int $pageId, string $title): string
    {
        $this->framework->initialize();

        return $this->slug->generate(
            html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $pageId,
            fn (string $alias): bool => false !== $this->connection->fetchOne('SELECT id FROM tl_article WHERE alias = ?', [$alias]),
        );
    }

    /** @param list<string> $tags e.g. contao.db.tl_page.12 */
    public function invalidate(array $tags): void
    {
        if ([] !== $tags) {
            $this->cacheTags?->invalidateTagsFor(array_values(array_unique($tags)));
        }
    }

    private function pageAliasExists(string $alias, PageModel $page): bool
    {
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM tl_page WHERE alias = ? AND id != ?', [$alias, $page->id]);
        $adapter = $this->framework->getAdapter(PageModel::class);

        foreach ($ids as $id) {
            $other = $adapter->findWithDetails((int) $id);

            if ($other && $other->rootId === $page->rootId) {
                return true;
            }
        }

        return false;
    }
}
