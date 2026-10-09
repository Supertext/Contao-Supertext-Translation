<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The back-end strings exist in English, German, French and Italian with the
 * same keys, placeholders, HTML tags and URLs, and every error code the bundle
 * reports has a message.
 */
final class LanguageFilesTest extends TestCase
{
    private const LANGUAGES = ['de', 'fr', 'it'];

    private const DIR = __DIR__.'/../../contao/languages';

    public static function files(): iterable
    {
        foreach (glob(self::DIR.'/en/*.php') as $file) {
            foreach (self::LANGUAGES as $language) {
                yield basename($file).' '.$language => [basename($file), $language];
            }
        }
    }

    #[DataProvider('files')]
    public function testTranslationMatchesEnglish(string $file, string $language): void
    {
        $english = self::flatten(self::load('en', $file));
        $translated = self::flatten(self::load($language, $file));

        $this->assertSame(array_keys($english), array_keys($translated), "$language/$file has different keys");

        foreach ($english as $key => $source) {
            $target = $translated[$key];
            $this->assertNotSame('', trim($target), "$language/$file $key is empty");
            $this->assertSame(self::placeholders($source), self::placeholders($target), "$language/$file $key: placeholders");
            $this->assertSame(self::findAll('/<\/?[a-z]+/', $source), self::findAll('/<\/?[a-z]+/', $target), "$language/$file $key: HTML tags");
            $this->assertSame(self::findAll('#https?://[^"\s]+#', $source), self::findAll('#https?://[^"\s]+#', $target), "$language/$file $key: URLs");

            if (str_contains($source, 'Supertext')) {
                $this->assertStringContainsString('Supertext', $target, "$language/$file $key");
            }
        }
    }

    public function testEveryErrorCodeHasAMessage(): void
    {
        $codes = [];

        foreach (glob(__DIR__.'/../../src/{Supertext,Translation}/*.php', GLOB_BRACE) as $file) {
            $php = file_get_contents($file);
            preg_match_all("/(?:new (?:Supertext|Translation)Exception\\(|\\[)'([a-z_]+)', '[A-Z]/", $php, $m);
            $codes = [...$codes, ...$m[1]];
        }

        $messages = self::load('en', 'default.php')['MSC']['supertext'];
        $codes = array_unique($codes);
        $this->assertContains('authentication_failure', $codes);

        foreach ($codes as $code) {
            $this->assertArrayHasKey('error_'.$code, $messages, "No MSC.supertext.error_$code for error code $code");
        }
    }

    /** @return array<string, mixed> */
    private static function load(string $language, string $file): array
    {
        $GLOBALS['TL_LANG'] = [];
        include self::DIR."/$language/$file";
        $strings = $GLOBALS['TL_LANG'];
        unset($GLOBALS['TL_LANG']);

        return $strings;
    }

    /** @return array<string, string> */
    private static function flatten(array $strings, string $prefix = ''): array
    {
        $flat = [];

        foreach ($strings as $key => $value) {
            $flat += \is_array($value) ? self::flatten($value, "$prefix$key.") : ["$prefix$key" => (string) $value];
        }

        ksort($flat);

        return $flat;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        return self::findAll('/%(?:\d+\$)?[sd]/', $text);
    }

    /** @return list<string> */
    private static function findAll(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $m);
        $found = $m[0];
        sort($found);

        return $found;
    }
}
