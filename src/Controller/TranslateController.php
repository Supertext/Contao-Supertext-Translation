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
use Supertext\ContaoTranslation\PluginHealth\KeyCheck;
use Supertext\ContaoTranslation\Reporting\Reporting;
use Supertext\ContaoTranslation\Sdk\Files\TranslationFileError;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Translation\FileExchange;
use Supertext\ContaoTranslation\Translation\Languages;
use Supertext\ContaoTranslation\Translation\PageTranslator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Translate with Supertext" screen, opened from the page operation in the site
 * structure. GET shows the target languages, POST runs the translation, or (`action`)
 * exports a translation file (`export`) or imports one (`import`, see FileExchange).
 * All run the Commando Center's version report and key check after the response (Reporting).
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
        private readonly Languages $languages,
        private readonly Reporting $reporting,
        private readonly FileExchange $files,
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
        $fileError = null;
        $imports = null;
        $action = $request->isMethod('POST') ? $request->request->getString('action') : '';

        if ('export' === $action) {
            $exportTargets = array_values(array_intersect(
                array_map('intval', (array) $request->request->all('export_targets')),
                array_column($roots, 'id'),
            ));
            $format = FileExchange::FORMAT_HTML === $request->request->getString('format') ? FileExchange::FORMAT_HTML : FileExchange::FORMAT_XLIFF;

            if ([] === $exportTargets) {
                $fileError = ['MSC.supertext.noTarget', []];
            } elseif (FileExchange::FORMAT_HTML === $format && 1 !== \count($exportTargets)) {
                $fileError = ['MSC.supertext.fileHtmlOneLanguage', []];
            } else {
                try {
                    $file = $this->files->export($id, $exportTargets, $format);
                } catch (TranslationFileError) {
                    $file = null; // invalid_job: nothing left to translate
                }

                if (null !== $file) {
                    $this->reporting->queue(Reporting::ON_REQUEST, $request);

                    return new Response($file['content'], 200, [
                        'Content-Type' => $file['contentType'],
                        'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['filename']),
                        'Cache-Control' => 'no-store',
                        'X-Content-Type-Options' => 'nosniff',
                    ]);
                }

                $fileError = ['MSC.supertext.fileNothing', []];
            }
        } elseif ('import' === $action) {
            [$imports, $fileError] = $this->import($request, $user);
        } elseif ($request->isMethod('POST')) {
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

                        // Supertext rejected the key: the key check becomes "invalid" (reported once).
                        if ('authentication_failure' === $target['errorCode']) {
                            $this->reporting->queue(Reporting::AUTHENTICATION_FAILED, $request, (int) ($target['errorParams'][0] ?? 401) ?: 401);
                        }
                    }
                }
                // Refresh "already translated" markers.
                $roots = array_values(array_filter(
                    $this->translator->targetRoots($id),
                    static fn ($r) => \in_array($r['id'], array_column($roots, 'id'), true),
                ));
            }
        }

        // Version report and key check, after the response (never slows down or breaks this screen).
        $this->reporting->queue(Reporting::ON_REQUEST, $request);

        // The Supertext code of each language (chosen, else exact match, else default); languages
        // without one can't be translated.
        foreach ($roots as &$root) {
            $root['supertext'] = $this->languages->targetOrNull($root['language'], $root['supertextLanguage']);
            $root['politeness_unsupported'] = $this->languages->politenessUnsupported($root['language'], $root['supertextLanguage']);
        }
        unset($root);

        $sourceLanguage = (string) $this->translator->sourceLanguage($id);

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
            'file_error' => $fileError,
            'file_action' => $action,
            'imports' => $imports,
            'api_key_configured' => $this->client->hasApiKey(),
            'source_language' => $sourceLanguage,
            'source_unmapped' => '' !== $sourceLanguage && null === $this->languages->sourceOrNull($sourceLanguage, $this->translator->sourceSupertextLanguage($id)),
            'status' => $user->isAdmin ? $this->status() : null,
            'back_url' => $this->generateUrl('contao_backend', ['do' => 'page']),
        ]);
    }

    /**
     * Imports an uploaded translation file. Each job in it is checked on its own: this
     * extension's file, the page still exists, the user may read it and edit the target root.
     * The upload is only read in memory, never stored.
     *
     * @return array{0: list<array<string, mixed>>|null, 1: array{0: string, 1: list<string|int>}|null}
     */
    private function import(Request $request, BackendUser $user): array
    {
        $upload = $request->files->get('translation_file');

        if (!$upload instanceof UploadedFile) {
            return [null, ['MSC.supertext.fileNoUpload', []]];
        }

        if (\in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($upload->isValid() && $upload->getSize() > FileExchange::MAX_UPLOAD_BYTES)) {
            return [null, ['MSC.supertext.file_too_large', []]];
        }

        if (!$upload->isValid()) {
            return [null, ['MSC.supertext.fileNoUpload', []]];
        }

        $content = file_get_contents($upload->getPathname(), false, null, 0, FileExchange::MAX_UPLOAD_BYTES + 1);

        if (false === $content) {
            return [null, ['MSC.supertext.fileNoUpload', []]];
        }

        try {
            $jobs = $this->files->read($content);
        } catch (TranslationFileError $e) {
            return [null, ['MSC.supertext.file_'.$e->errorCode, []]];
        }

        $mayEdit = function (int $pageId, int $rootId) use ($user): bool {
            if (!self::canUse($user)) {
                return false;
            }

            $page = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$pageId]);
            $root = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$rootId]);

            return $page && $root
                && $this->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', new ReadAction('tl_page', $page))
                && $this->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', new UpdateAction('tl_page', $root));
        };

        $results = [];
        $includeChanged = $request->request->getBoolean('include_changed');

        foreach ($jobs as $job) {
            try {
                $results[] = $this->files->import($job, $includeChanged, $mayEdit);
            } catch (\Throwable $e) {
                $this->logger?->error(\sprintf('Supertext import of %s failed: %s', $job->reference, $e->getMessage()));
                $results[] = ['page' => $job->reference, 'language' => $job->targetLanguage, 'root' => '', 'error' => 'fileWriteFailed', 'errorParams' => [], 'counts' => [], 'imported' => 0, 'pageId' => null, 'targetPageId' => null];
            }
        }

        return [$results, null];
    }

    /**
     * Administrators only: the latest API key check and whether version information is sent.
     *
     * @return array{check: array{status: string, time: string, http_status: string}|null, reports: bool, reports_env: bool}
     */
    private function status(): array
    {
        $check = $this->reporting->lastCheck();

        return [
            'check' => null === $check ? null : [
                'status' => \in_array($check->status, [KeyCheck::OK, KeyCheck::INVALID, KeyCheck::MISSING, KeyCheck::UNREACHABLE], true) ? $check->status : KeyCheck::ERROR,
                'time' => self::formatTime($check->checkedAt),
                'http_status' => null === $check->httpStatus ? '–' : (string) $check->httpStatus,
            ],
            'reports' => $this->reporting->enabled(),
            'reports_env' => $this->reporting->decidedByEnvironment(),
        ];
    }

    /** The check's UTC time in the server's time zone (Y-m-d H:i; ISO order reads the same in every language). */
    private static function formatTime(string $iso): string
    {
        $time = strtotime($iso);

        return false === $time ? $iso : date('Y-m-d H:i', $time);
    }

    public static function canUse(BackendUser $user): bool
    {
        return $user->isAdmin || !empty($user->supertext);
    }
}
