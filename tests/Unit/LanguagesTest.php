<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\Translation\Languages;
use Supertext\ContaoTranslation\Sdk\LanguageCodes;

final class LanguagesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function sources(): iterable
    {
        yield 'bare source' => ['en', 'en'];
        yield 'regional source => bare' => ['en-US', 'en'];
        yield 'underscore and case' => ['DE_ch', 'de'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('sources')]
    public function testSource(string $language, string $expected): void
    {
        $this->assertSame($expected, (new Languages())->source($language));
    }

    /** @return iterable<string, array{string, string}> */
    public static function targets(): iterable
    {
        yield 'bare target => default region' => ['de', 'de-DE'];
        yield 'bare en => en-US' => ['en', 'en-US'];
        yield 'bare zh => zh-CN' => ['zh', 'zh-CN'];
        yield 'regional target kept' => ['fr-CH', 'fr-CH'];
        yield 'underscore normalized' => ['de_CH', 'de-CH'];
        yield 'case normalized' => ['DE-ch', 'de-CH'];
        yield 'script kept' => ['zh-hant', 'zh-Hant'];
        yield 'numeric region kept' => ['es-419', 'es-419'];
        yield 'unknown bare code unchanged' => ['rm', 'rm'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('targets')]
    public function testTarget(string $language, string $expected): void
    {
        $this->assertSame($expected, (new Languages())->target($language));
    }

    public function testConfiguredCodeWins(): void
    {
        $languages = new Languages(['de' => 'de-CH', 'fr' => 'fr_ch', 'it' => 'it']);

        $this->assertSame('de-CH', $languages->target('de'));
        $this->assertSame('fr-CH', $languages->target('fr'));
        $this->assertSame('it-IT', $languages->target('it'));
        $this->assertSame('de', $languages->source('de'));
        $this->assertSame('fr', $languages->source('fr'));
    }

    public function testSourceUsesTheConfiguredCodesLanguage(): void
    {
        $this->assertSame('fr', (new Languages(['en' => 'fr-CH']))->source('en'));
    }

    public function testEveryDefaultIsRegional(): void
    {
        foreach (LanguageCodes::DEFAULT_REGIONS as $bare => $regional) {
            $this->assertStringStartsWith($bare.'-', $regional);
            $this->assertSame($regional, LanguageCodes::target($regional));
        }
    }
}
