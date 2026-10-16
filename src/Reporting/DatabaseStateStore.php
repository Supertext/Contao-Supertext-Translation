<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Reporting;

use Doctrine\DBAL\Connection;
use Supertext\ContaoTranslation\PluginHealth\ReporterState;

/**
 * Keeps the reporter's state as JSON in one row of `tl_supertext_state` (created by
 * Contao's database update from contao/dca/tl_supertext_state.php). Never contains the
 * API key: the key check keeps only its SHA-256 fingerprint, which never leaves the site.
 */
final class DatabaseStateStore implements StateStore
{
    public const TABLE = 'tl_supertext_state';

    private const NAME = 'reporter';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(): ReporterState
    {
        $data = $this->connection->fetchOne('SELECT data FROM '.self::TABLE.' WHERE name = ?', [self::NAME]);

        return ReporterState::from(\is_string($data) ? $data : null);
    }

    public function save(ReporterState $state): void
    {
        $row = ['data' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'tstamp' => time()];

        // Not "update, else insert": MySQL counts an update that changes nothing as 0 rows.
        if (false !== $this->connection->fetchOne('SELECT id FROM '.self::TABLE.' WHERE name = ?', [self::NAME])) {
            $this->connection->update(self::TABLE, $row, ['name' => self::NAME]);
        } else {
            $this->connection->insert(self::TABLE, ['name' => self::NAME, ...$row]);
        }
    }
}
