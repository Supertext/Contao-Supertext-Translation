<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Source page this page was translated from (0 = not created by Supertext).
$GLOBALS['TL_DCA']['tl_page']['fields']['supertext_source'] = [
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
    'eval' => ['doNotCopy' => true],
];

// Website roots: the Supertext language code for this root's language (both directions) and the
// tone. Options, preselection, validation and the politeness visibility: RootLanguageListener.
// '' = the SDK's suggestion for the root's language (exact match, else default, else none).
$GLOBALS['TL_DCA']['tl_page']['fields']['supertext_language'] = [
    'inputType' => 'select',
    'eval' => ['includeBlankOption' => true, 'chosen' => true, 'submitOnChange' => true, 'maxlength' => 32, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
];

$GLOBALS['TL_DCA']['tl_page']['fields']['supertext_politeness'] = [
    'inputType' => 'select',
    'options' => ['default', 'less', 'more'],
    'reference' => &$GLOBALS['TL_LANG']['tl_page']['supertext_politeness_options'],
    'eval' => ['tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 8, 'default' => 'default'],
];

foreach (['root', 'rootfallback'] as $palette) {
    if (isset($GLOBALS['TL_DCA']['tl_page']['palettes'][$palette])) {
        PaletteManipulator::create()
            ->addLegend('supertext_legend', 'language_legend', PaletteManipulator::POSITION_AFTER)
            ->addField(['supertext_language', 'supertext_politeness'], 'supertext_legend', PaletteManipulator::POSITION_APPEND)
            ->applyToPalette($palette, 'tl_page')
        ;
    }
}

// "Translate with Supertext" in the site structure. Hidden for users without the
// Supertext permission (see SupertextOperationListener).
$GLOBALS['TL_DCA']['tl_page']['list']['operations']['supertext'] = [
    'route' => 'supertext_translation_translate',
    'icon' => 'bundles/supertexttranslation/supertext.svg',
    'primary' => true,
];
