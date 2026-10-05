<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Controller\Backend\AbstractBackendController;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Translation\PageTranslator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Translate with Supertext" screen, opened from the page operation in the site
 * structure. GET shows the target languages, POST runs the translation.
 */
#[Route(
    '%contao.backend.route_prefix%/supertext/translate/{id}',
    name: 'supertext_translation_translate',
    requirements: ['id' => '\d+'],
    defaults: ['_scope' => 'backend', '_store_referrer' => false],
    methods: ['GET', 'POST'],
)]
final class TranslateController extends AbstractBackendController
{
    public function __construct(
        private readonly PageTranslator $translator,
        private readonly SupertextClient $client,
        private readonly Connection $connection,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(Request $request, int $id): Response
    {
        $this->initializeContaoFramework();

        $user = $this->getUser();

        if (!$user instanceof BackendUser || !self::canUse($user)) {
            throw $this->createAccessDeniedException('You are not allowed to use Supertext translation.');
        }

        $page = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$id]);

        if (!$page) {
            throw $this->createNotFoundException(\sprintf('Page %d not found.', $id));
        }

        $this->denyAccessUnlessGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', new ReadAction('tl_page', $page));

        // Only offer language roots the user may edit.
        $roots = [];

        foreach ($this->translator->targetRoots($id) as $root) {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$root['id']]);

            if ($row && $this->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', new UpdateAction('tl_page', $row))) {
                $roots[] = $root;
            }
        }

        $report = null;
        $error = null;
        $selected = [];
        $includeSubpages = 'root' === $page['type'];

        if ($request->isMethod('POST')) {
            $selected = array_values(array_intersect(
                array_map('intval', (array) $request->request->all('targets')),
                array_column($roots, 'id'),
            ));
            $includeSubpages = 'root' === $page['type'] || $request->request->getBoolean('subpages');

            if ([] === $selected) {
                $error = 'MSC.supertext.noTarget';
            } elseif (!$this->client->hasApiKey()) {
                $error = 'MSC.supertext.noApiKey';
            } else {
                @set_time_limit(0);
                $report = $this->translator->translatePage($id, $selected, $includeSubpages);

                // Failures also go to Contao's system log.
                foreach ($report->targets as $target) {
                    if (!$target['ok']) {
                        $this->logger?->error(\sprintf('Supertext translation of page ID %d into %s failed: %s', $id, $target['root']['language'], $target['error']));
                    }
                }
                // Refresh "already translated" markers.
                $roots = array_values(array_filter(
                    $this->translator->targetRoots($id),
                    static fn ($r) => \in_array($r['id'], array_column($roots, 'id'), true),
                ));
            }
        }

        return $this->render('@SupertextTranslation/translate.html.twig', [
            'headline' => 'Supertext',
            'title' => 'Supertext',
            'page' => [
                'id' => (int) $page['id'],
                'title' => html_entity_decode((string) $page['title'], ENT_QUOTES | ENT_HTML5),
                'type' => $page['type'],
                'has_subpages' => false !== $this->connection->fetchOne('SELECT id FROM tl_page WHERE pid = ? LIMIT 1', [$id]),
            ],
            'roots' => $roots,
            'selected' => $selected,
            'include_subpages' => $includeSubpages,
            'report' => $report,
            'error' => $error,
            'api_key_configured' => $this->client->hasApiKey(),
            'back_url' => $this->generateUrl('contao_backend', ['do' => 'page']),
        ]);
    }

    public static function canUse(BackendUser $user): bool
    {
        return $user->isAdmin || !empty($user->supertext);
    }
}
