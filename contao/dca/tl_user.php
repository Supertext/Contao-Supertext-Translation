<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Permission to start Supertext translations (administrators always may).
$GLOBALS['TL_DCA']['tl_user']['fields']['supertext'] = [
    'exclude' => true,
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'clr'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

PaletteManipulator::create()
    ->addLegend('supertext_legend', 'pagemounts_legend', PaletteManipulator::POSITION_BEFORE)
    ->addField('supertext', 'supertext_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('extend', 'tl_user')
    ->applyToPalette('custom', 'tl_user')
;
