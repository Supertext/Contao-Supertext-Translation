<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Cron;

use Supertext\ContaoTranslation\Translation\ReviewCorrections;

/** Contao cron job (hourly): writes reviewers' corrections into the translations. Never throws. */
class ReviewCorrectionsCron
{
    public function __construct(
        private readonly ReviewCorrections $corrections,
        private readonly bool $enabled = true,
    ) {
    }

    public function __invoke(): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->corrections->run();
        } catch (\Throwable) {
            // Logged by ReviewCorrections where it can be; a cron run must never fail Contao's cron.
        }
    }
}
