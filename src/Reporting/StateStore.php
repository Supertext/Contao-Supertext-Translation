<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Reporting;

use Supertext\ContaoTranslation\PluginHealth\ReporterState;

/**
 * Where the Commando Center reporter keeps its state between requests (what was reported
 * last, the latest API key check). Implementations may throw; Reporting catches everything.
 */
interface StateStore
{
    public function load(): ReporterState;

    public function save(ReporterState $state): void;
}
