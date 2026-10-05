<?php

declare(strict_types=1);

// Source record this one was translated from (0 = not created by Supertext).
$GLOBALS['TL_DCA']['tl_article']['fields']['supertext_source'] = [
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
    'eval' => ['doNotCopy' => true],
];
