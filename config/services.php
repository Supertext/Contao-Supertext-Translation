<?php

declare(strict_types=1);

use Supertext\ContaoTranslation\Command\ApplyReviewCorrectionsCommand;
use Supertext\ContaoTranslation\Controller\PingController;
use Supertext\ContaoTranslation\Cron\ReviewCorrectionsCron;
use Supertext\ContaoTranslation\Controller\TranslateController;
use Supertext\ContaoTranslation\EventListener\RootLanguageListener;
use Supertext\ContaoTranslation\EventListener\SupertextOperationListener;
use Supertext\ContaoTranslation\Reporting\DatabaseStateStore;
use Supertext\ContaoTranslation\Reporting\Reporting;
use Supertext\ContaoTranslation\Reporting\StateStore;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Translation\ContaoHooks;
use Supertext\ContaoTranslation\Translation\FieldMap;
use Supertext\ContaoTranslation\Translation\FileExchange;
use Supertext\ContaoTranslation\Translation\Languages;
use Supertext\ContaoTranslation\Translation\PageTranslator;
use Supertext\ContaoTranslation\Translation\ReviewCorrections;
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
            param('supertext_translation.review_url'),
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

    // Corrections from Supertext Localization Review, hourly and with supertext:review:apply.
    $services->set(ReviewCorrections::class)
        ->arg('$logger', service('monolog.logger.contao.general')->nullOnInvalid())
        ->public()
    ;
    $services->set(ApplyReviewCorrectionsCommand::class);
    $services->set(ReviewCorrectionsCron::class)
        ->arg('$enabled', param('supertext_translation.review_corrections'))
        ->tag('contao.cronjob', ['interval' => 'hourly'])
    ;

    // Export for translation / Import translation (XLIFF and HTML files).
    $services->set(FileExchange::class);

    $services->set(TranslateController::class)
        ->arg('$logger', service('monolog.logger.contao.error')->nullOnInvalid())
        ->public()
    ;

    $services->set(SupertextOperationListener::class);

    // Supertext language and politeness selects in a website root's settings.
    $services->set(RootLanguageListener::class);

    // Commando Center: version reports, API key check, ping (see src/Reporting/Reporting.php).
    $services->set(DatabaseStateStore::class)->args([service('database_connection')]);
    $services->alias(StateStore::class, DatabaseStateStore::class);

    $services->set(Reporting::class)
        ->args([
            '$store' => service(StateStore::class),
            '$httpClient' => service('http_client'),
            '$apiKey' => param('supertext_translation.api_key'),
            '$apiUrl' => param('supertext_translation.api_url'),
            '$environment' => param('supertext_translation.environment'),
            '$setting' => param('supertext_translation.version_reports'),
            '$envReports' => '%env(default::SUPERTEXT_VERSION_REPORTS)%',
            '$reportUrl' => '%env(default::SUPERTEXT_REPORT_URL)%',
            '$router' => service('router'),
            '$logger' => service('logger')->nullOnInvalid(),
            '$now' => null,
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onKernelTerminate'])
    ;

    $services->set(PingController::class)->public();
};
