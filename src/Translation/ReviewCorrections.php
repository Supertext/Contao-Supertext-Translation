<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Supertext\ContaoTranslation\Sdk\V1\Correction;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Supertext\SupertextException;

/**
 * Writes reviewers' corrections (Supertext Localization Review) into the translations without
 * translating again: for every website root with *Translation review* on, fetches the
 * corrections for its Supertext language from the review service, writes each one where the
 * field still holds what was delivered (PageTranslator::applyCorrection()) and acknowledges the
 * written ones. Skipped corrections stay pending: they arrive with the next translation, and
 * another site on the same API key can still take its own. Runs hourly (Contao cron) and with
 * `supertext:review:apply`.
 */
class ReviewCorrections
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SupertextClient $client,
        private readonly PageTranslator $translator,
        private readonly Languages $languages,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /**
     * @return array{roots: list<array{id: int, title: string, language: string, counts: array<string, int>}>, acknowledged: int, error: string|null}
     */
    public function run(): array
    {
        $result = ['roots' => [], 'acknowledged' => 0, 'error' => null];
        $review = $this->client->forReview(true);

        if (!$this->client->reviewEnabled() || !$review->hasApiKey()) {
            return $result;
        }

        $roots = $this->connection->fetchAllAssociative("SELECT id, title, language, supertext_language FROM tl_page WHERE type = 'root' AND supertext_review = 1 ORDER BY sorting");
        $byTarget = [];

        foreach ($roots as $root) {
            try {
                $byTarget[$this->languages->target((string) $root['language'], (string) $root['supertext_language'])][] = $root;
            } catch (SupertextException) {
                // No Supertext language yet: nothing was translated through the review service.
            }
        }

        $written = [];

        try {
            foreach ($byTarget as $target => $targetRoots) {
                $corrections = $review->pendingCorrections((string) $target);

                foreach ($targetRoots as $root) {
                    $counts = [];

                    foreach ($corrections as $c) {
                        $outcome = $this->apply($c, (int) $root['id']);
                        $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;

                        if (PageTranslator::CORRECTION_WRITTEN === $outcome) {
                            $written[$c->id] = $c;
                        }
                    }

                    $result['roots'][] = ['id' => (int) $root['id'], 'title' => (string) $root['title'], 'language' => (string) $target, 'counts' => $counts];
                }
            }
        } catch (SupertextException $e) {
            $result['error'] = $e->errorCode;
        } finally {
            // Whatever was written is confirmed, even if a later language failed.
            try {
                $result['acknowledged'] = $review->acknowledgeCorrections(array_values($written));
            } catch (SupertextException $e) {
                $result['error'] ??= $e->errorCode;
            }
        }

        foreach ($result['roots'] as $r) {
            if (($r['counts'][PageTranslator::CORRECTION_WRITTEN] ?? 0) > 0) {
                $this->logger?->info(\sprintf('Supertext review: %d correction(s) written into "%s" (%s)', $r['counts'][PageTranslator::CORRECTION_WRITTEN], $r['title'], $r['language']));
            }
        }

        if (null !== $result['error']) {
            $this->logger?->error('Supertext review: fetching or confirming corrections failed: '.$result['error']);
        }

        return $result;
    }

    private function apply(Correction $c, int $rootId): string
    {
        try {
            return $this->translator->applyCorrection($c->segmentId, $rootId, $c->sourceHtml, $c->previousHtml, $c->html);
        } catch (\Throwable $e) {
            $this->logger?->error(\sprintf('Supertext review: correction %s could not be written: %s', $c->segmentId, $e->getMessage()));

            return 'error';
        }
    }
}
