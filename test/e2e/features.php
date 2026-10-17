<?php

declare(strict_types=1);

/*
 * Compares the language lists the plugin ships (the SDK's V1\Languages, used for the
 * language mapping) with what the real Supertext API offers right now (`GET features`,
 * cost-free), and checks the demo's language pairs. Runs inside the demo container:
 *
 *   docker cp test/e2e/features.php demo:/tmp/features.php
 *   docker exec demo php /tmp/features.php
 *
 * Errors (exit 1): a code the plugin would send that the API no longer accepts, or a demo
 * pair or a default target the API doesn't offer. Warnings only: new codes the plugin doesn't know yet, and a
 * different politeness support. Output uses GitHub Actions annotations.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 */

use Supertext\ContaoTranslation\Sdk\V1\Client;
use Supertext\ContaoTranslation\Sdk\V1\Languages;
use Supertext\ContaoTranslation\Sdk\V1\Protocol;

require '/var/www/html/vendor/autoload.php';

$key = (string) getenv('SUPERTEXT_API_KEY');
if ('' === trim($key)) {
    echo "::error::SUPERTEXT_API_KEY is not set\n";
    exit(1);
}

$client = new Client($key, (string) getenv('SUPERTEXT_API_URL') ?: Protocol::LIVE_BASE_URL, userAgent: 'Contao-Supertext-Translation-E2E');
$pairs = $client->getFeatures();
$errors = 0;

if ([] === $pairs) {
    echo "::error::GET features returned no language pairs\n";
    exit(1);
}

$liveSources = array_values(array_unique(array_map(static fn ($p) => $p->sourceLang, $pairs)));
$liveTargets = array_values(array_unique(array_map(static fn ($p) => $p->targetLang, $pairs)));
$sdkSources = array_column(Languages::SOURCES, 'code');
$sdkTargets = array_column(Languages::TARGETS, 'code');
printf("Live API: %d pairs, %d sources, %d targets. Plugin: %d sources, %d targets.\n", count($pairs), count($liveSources), count($liveTargets), count($sdkSources), count($sdkTargets));

foreach ([['source', $sdkSources, $liveSources], ['target', $sdkTargets, $liveTargets]] as [$kind, $sdk, $live]) {
    foreach (array_diff($sdk, $live) as $code) {
        echo "::error::The plugin offers $kind language $code, but the API no longer lists it (update the SDK's spec/languages.json)\n";
        ++$errors;
    }
    foreach (array_diff($live, $sdk) as $code) {
        echo "::warning::The API offers $kind language $code, which the plugin doesn't know yet (update the SDK's spec/languages.json)\n";
    }
}

// Politeness: per target, from the pairs with an English source.
foreach (Languages::TARGETS as $target) {
    $fromEnglish = array_filter($pairs, static fn ($p) => 'en' === $p->sourceLang && $p->targetLang === $target['code']);
    if ([] === $fromEnglish) {
        continue;
    }
    $live = in_array('supports_politeness', array_merge(...array_map(static fn ($p) => $p->features, array_values($fromEnglish))), true);
    if ($live !== $target['politeness']) {
        printf("::warning::Politeness for %s: the plugin says %s, the API says %s\n", $target['code'], $target['politeness'] ? 'yes' : 'no', $live ? 'yes' : 'no');
    }
}

// The demo's pairs: English into each target root's language, as the plugin maps it.
foreach (['de-CH', 'fr-CH', 'it-CH'] as $root) {
    $target = Languages::target($root);
    $ok = [] !== array_filter($pairs, static fn ($p) => 'en' === $p->sourceLang && $p->targetLang === $target);
    echo $ok ? "ok   en → $target (demo root $root)\n" : "::error::The API doesn't offer en → $target (demo root $root)\n";
    $errors += $ok ? 0 : 1;
}

// The defaults a website root without a chosen Supertext language is sent as (SDK 0.4.0),
// e.g. de → de-DE, zh-TW → zh-Hant, es-ES → es: each must be a live target.
foreach (['de', 'en', 'fr', 'it', 'pt', 'zh', 'zh-TW', 'sr', 'es-ES', 'nl-NL'] as $language) {
    $target = Languages::target($language);
    $ok = in_array($target, $liveTargets, true);
    echo $ok ? "ok   default $language → $target\n" : "::error::The default for $language is $target, which the API doesn't list as a target\n";
    $errors += $ok ? 0 : 1;
}

exit($errors > 0 ? 1 : 0);
