<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Supertext\ContaoTranslation\Controller\TranslateController;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Hides "Translate with Supertext" for users without the Supertext permission.
 */
#[AsCallback(table: 'tl_page', target: 'list.operations.supertext.button')]
final class SupertextOperationListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(DataContainerOperation $operation): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof BackendUser || !TranslateController::canUse($user)) {
            $operation->hide();
        }
    }
}
