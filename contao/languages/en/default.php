<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['MSC']['supertext'] = [
    'headline' => 'Translate “%s” with Supertext',
    'targets' => 'Translate into',
    'help' => 'The page, its articles and content elements are copied into each selected language and translated. New pages are created unpublished so you can review them. Existing translations are updated; the previous state stays in the version history.',
    'subpages' => 'Also translate all subpages',
    'rootHelp' => 'This is a website root: all pages of this language are translated. This can take several minutes.',
    'exists' => 'translation exists, will be updated',
    'new' => 'not translated yet',
    'submit' => 'Translate',
    'running' => 'Translating… please wait',
    'done' => '%1$s page(s), %2$s article(s) and %3$s content element(s) translated.',
    'hidden' => '%s element(s) no longer in the source were hidden.',
    'created' => 'new, unpublished — review and publish it',
    'missing' => '%s text(s) came back empty from Supertext and kept the source text. Please review.',
    'noTarget' => 'Please choose at least one language.',
    'noApiKey' => 'No Supertext API key is configured. Ask your administrator to set SUPERTEXT_API_KEY.',
    'noRoots' => 'There is no other language of this website you may edit. Create a website root for each language first (Site structure → new page of type “Website root” with its own language).',
];
