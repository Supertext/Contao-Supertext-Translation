<?php

declare(strict_types=1);

use Supertext\ContaoTranslation\Controller\TranslateController;
use Supertext\ContaoTranslation\EventListener\SupertextOperationListener;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Translation\ContaoHooks;
use Supertext\ContaoTranslation\Translation\FieldMap;
use Supertext\ContaoTranslation\Translation\Languages;
use Supertext\ContaoTranslation\Translation\PageTranslator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(SupertextClient::class)
        ->factory([SupertextClient::class, 'create'])
        ->args([
            service('http_client'),
            param('supertext_translation.api_key'),
            param('supertext_translation.api_url'),
            param('supertext_translation.environment'),
            param('supertext_translation.poll_interval_ms'),
            param('supertext_translation.timeout_ms'),
        ])
    ;

    $services->set(FieldMap::class)->args([param('supertext_translation.fields')]);

    $services->set(Languages::class)->args([
        param('supertext_translation.language_map'),
        param('supertext_translation.politeness'),
    ]);

    $services->set(ContaoHooks::class)
        ->args([
            service('contao.framework'),
            service('database_connection'),
            service('contao.slug'),
            service('contao.cache.entity_tags')->nullOnInvalid(),
        ])
    ;

    $services->set(PageTranslator::class)->public();

    $services->set(TranslateController::class)
        ->arg('$logger', service('monolog.logger.contao.error')->nullOnInvalid())
        ->public()
    ;

    $services->set(SupertextOperationListener::class);
};
