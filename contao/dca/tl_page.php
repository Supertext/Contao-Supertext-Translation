<?php

declare(strict_types=1);

// Source page this page was translated from (0 = not created by Supertext).
$GLOBALS['TL_DCA']['tl_page']['fields']['supertext_source'] = [
    'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
    'eval' => ['doNotCopy' => true],
];

// "Translate with Supertext" in the site structure. Hidden for users without the
// Supertext permission (see SupertextOperationListener).
$GLOBALS['TL_DCA']['tl_page']['list']['operations']['supertext'] = [
    'route' => 'supertext_translation_translate',
    'icon' => 'bundles/supertexttranslation/supertext.svg',
    'primary' => true,
];
