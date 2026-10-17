<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\Supertext\SupertextException;
use Supertext\ContaoTranslation\Translation\Languages;

final class LanguagesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function sources(): iterable
    {
        yield 'bare source' => ['en', 'en'];
        yield 'regional source => its language' => ['en-US', 'en'];
        yield 'underscore and case' => ['DE_ch', 'de'];
        yield 'empty (auto-detect)' => ['', ''];
    }

    #[DataProvider('sources')]
    public function testSource(string $language, string $expected): void
    {
        $this->assertSame($expected, (new Languages())->source($language));
    }

    /** @return iterable<string, array{string, string}> */
    public static function targets(): iterable
    {
        yield 'regional target kept' => ['fr-CH', 'fr-CH'];
        yield 'underscore normalized' => ['de_CH', 'de-CH'];
        yield 'case normalized' => ['DE-ch', 'de-CH'];
        yield 'script' => ['zh-hant', 'zh-Hant'];
        yield 'bare where Supertext wants it bare' => ['es', 'es'];
        yield 'Romansh' => ['rm', 'rm'];
    }

    #[DataProvider('targets')]
    public function testTarget(string $language, string $expected): void
    {
        $this->assertSame($expected, (new Languages())->target($language));
    }

    /** @return iterable<string, array{string, string}> */
    public static function defaults(): iterable
    {
        // SDK 0.4.0: an unmapped language uses its default where one is obvious.
        yield 'bare German' => ['de', 'de-DE'];
        yield 'bare English' => ['en', 'en-US'];
        yield 'bare Portuguese' => ['pt', 'pt-PT'];
        yield 'Spanish with a region' => ['es-ES', 'es'];
        yield 'Chinese (China)' => ['zh-CN', 'zh-Hans'];
        yield 'Chinese (Taiwan)' => ['zh_TW', 'zh-Hant'];
        yield 'Serbian' => ['sr', 'sr-Latn'];
    }

    #[DataProvider('defaults')]
    public function testDefaultTarget(string $language, string $expected): void
    {
        $this->assertSame($expected, (new Languages())->target($language));
        $this->assertSame($expected, Languages::suggest($language), 'the select preselects the same code');
    }

    /** @return iterable<string, array{string}> */
    public static function unmapped(): iterable
    {
        // No guessed regions: these need a Supertext language.
        yield 'English (Ireland)' => ['en-IE'];
        yield 'German (Liechtenstein)' => ['de-LI'];
        yield 'Swiss German' => ['gsw'];
        yield 'Serbian (Serbia)' => ['sr-RS'];
        yield 'unknown' => ['xx'];
    }

    #[DataProvider('unmapped')]
    public function testUnmappedTargetIsAnError(string $language): void
    {
        try {
            (new Languages())->target($language);
            $this->fail('Expected unsupported_language');
        } catch (SupertextException $e) {
            $this->assertSame('unsupported_language', $e->errorCode);
            $this->assertSame([$language], $e->messageParams(), 'the message names the language');
        }

        $this->assertNull((new Languages())->targetOrNull($language));
        $this->assertSame('', Languages::suggest($language), 'the select shows "— choose —"');
        $this->assertSame('en-GB', (new Languages())->target($language, 'en-GB'), 'a chosen Supertext language makes it work');
    }

    public function testUnknownSourceIsAnError(): void
    {
        try {
            (new Languages())->source('xx-YY');
            $this->fail('Expected unsupported_language');
        } catch (SupertextException $e) {
            $this->assertSame('unsupported_language', $e->errorCode);
            $this->assertSame(['xx-YY'], $e->messageParams());
        }

        $this->assertNull((new Languages())->sourceOrNull('xx-YY'));
    }

    public function testMappingWins(): void
    {
        $languages = new Languages(['de' => 'de-CH', 'fr_ch' => 'fr-FR', 'en' => 'en-GB', 'no' => 'nb']);

        $this->assertSame('de-CH', $languages->target('de'));
        $this->assertSame('fr-FR', $languages->target('fr-CH'), 'keys compared ignoring case and _/-');
        $this->assertSame('nb', $languages->target('no'));
        // One mapping for both directions: the source is the mapped code's language.
        $this->assertSame('de', $languages->source('de'));
        $this->assertSame('en', $languages->source('en'));
        $this->assertSame('nb', $languages->source('no'));
    }

    public function testMappedValueIsSentAsItIs(): void
    {
        // A language Supertext adds later works before the SDK lists it.
        $this->assertSame('xx-YY', (new Languages(['xx' => 'xx-YY']))->target('xx'));
    }

    public function testRootChoiceAndConfigurationOverride(): void
    {
        $languages = new Languages(['de-CH' => 'de-DE']);

        $this->assertSame('de-AT', $languages->target('de', 'de-AT'), 'the root\'s Supertext language');
        $this->assertSame('de', $languages->source('de', 'de-AT'));
        $this->assertSame('nb', $languages->source('no', 'nb'), 'one choice for both directions');
        $this->assertSame('de-DE', $languages->target('de_CH', 'gsw-u-sd-chzh'), 'language_map overrides the root');
        $this->assertSame('de-DE', $languages->configured('de_ch'));
        $this->assertNull($languages->configured('fr-CH'));
    }

    public function testRootPolitenessOnlyWhereTheTargetOffersIt(): void
    {
        $languages = new Languages([], ['fr-CH' => 'less']);

        $this->assertSame('more', $languages->politeness('de', '', 'more'));
        $this->assertSame('default', $languages->politeness('en', '', 'more'), 'en-US has no formal/informal: not sent');
        $this->assertSame('default', $languages->politeness('de', 'gsw-u-sd-chzh', 'more'));
        $this->assertSame('default', $languages->politeness('de', '', 'nonsense'));
        $this->assertSame('less', $languages->politeness('fr-CH', '', 'more'), 'configuration wins');
        $this->assertSame('less', $languages->configuredPoliteness('fr-CH'));
    }

    public function testSelectOptions(): void
    {
        $codes = Languages::targetCodes();
        $this->assertContains('de-CH', $codes);
        $this->assertContains('gsw-u-sd-chzh', $codes);
        $this->assertNotContains('de', $codes);

        $labels = Languages::targetLabels('de');
        $this->assertSame($codes, array_values(array_intersect($codes, array_keys($labels))), 'every code has a label');
        $this->assertCount(\count($codes), $labels);
        $this->assertStringEndsWith('(de-CH)', $labels['de-CH'], 'the code is shown next to the name');

        if (class_exists(\Locale::class)) {
            $this->assertSame('Deutsch (Schweiz) (de-CH)', $labels['de-CH'], 'names in the back-end language');
            $this->assertSame('Schweizerdeutsch (Zürich) (gsw-u-sd-chzh)', $labels['gsw-u-sd-chzh'], 'dialects: language and place');
            $this->assertSame('Swiss German (Bern) (gsw-u-sd-chbe)', Languages::targetLabels('en')['gsw-u-sd-chbe']);
            $this->assertSame('Swiss German (Zurich) (gsw-u-sd-chzh)', Languages::targetLabels('ja')['gsw-u-sd-chzh'], 'Supertext\'s name for other back-end languages');
        }
    }

    public function testPolitenessWarningOnlyForTargetsWithoutIt(): void
    {
        $languages = new Languages(['en' => 'en-GB'], ['de-CH' => 'more', 'en' => 'more', 'rm' => 'less', 'fr-CH' => 'default']);

        $this->assertSame('more', $languages->politeness('de-CH'));
        $this->assertSame('default', $languages->politeness('it-CH'));
        $this->assertFalse($languages->politenessUnsupported('de-CH'));
        $this->assertTrue($languages->politenessUnsupported('en'));
        $this->assertTrue($languages->politenessUnsupported('rm'));
        $this->assertFalse($languages->politenessUnsupported('fr-CH'));
        $this->assertFalse($languages->politenessUnsupported('de-LI'), 'no Supertext language: shown as not mapped instead');
    }
}
