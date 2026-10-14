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

    /** @return iterable<string, array{string}> */
    public static function unmapped(): iterable
    {
        // No default regions: these were guessed before (de-DE, es-ES, zh-CN) and Supertext rejects most guesses.
        yield 'bare German' => ['de'];
        yield 'bare English' => ['en'];
        yield 'Spanish with a region' => ['es-ES'];
        yield 'Chinese with a region' => ['zh-CN'];
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

    public function testPolitenessWarningOnlyForTargetsWithoutIt(): void
    {
        $languages = new Languages(['en' => 'en-GB'], ['de-CH' => 'more', 'en' => 'more', 'rm' => 'less', 'fr-CH' => 'default']);

        $this->assertSame('more', $languages->politeness('de-CH'));
        $this->assertSame('default', $languages->politeness('it-CH'));
        $this->assertFalse($languages->politenessUnsupported('de-CH'));
        $this->assertTrue($languages->politenessUnsupported('en'));
        $this->assertTrue($languages->politenessUnsupported('rm'));
        $this->assertFalse($languages->politenessUnsupported('fr-CH'));
        $this->assertFalse($languages->politenessUnsupported('de'), 'unmapped: shown as not mapped instead');
    }
}
