<?php

declare(strict_types=1);

// State of the Supertext version reports and API key check (one row, JSON; see
// src/Reporting/DatabaseStateStore.php). No back-end module: not shown anywhere.
$GLOBALS['TL_DCA']['tl_supertext_state'] = [
    'config' => [
        'sql' => [
            'keys' => ['id' => 'primary', 'name' => 'unique'],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'name' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        'data' => ['sql' => ['type' => 'text', 'notnull' => false]],
    ],
];
