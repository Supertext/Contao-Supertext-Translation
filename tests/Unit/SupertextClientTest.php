<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Supertext\SupertextException;
use Supertext\ContaoTranslation\Tests\FakeSupertext;

final class SupertextClientTest extends TestCase
{
    private function client(FakeSupertext $fake, string $key = 'test-key', int $timeoutMs = 180_000): SupertextClient
    {
        return new SupertextClient($fake->client(), $key, 'https://api.test/v1', 1000, $timeoutMs, static function (): void {});
    }

    public function testRoundTrip(): void
    {
        $fake = new FakeSupertext(['translating', 'translating', 'done']);
        $html = $this->client($fake)->translateHtml('<div data-st-id="a">Hallo</div>', 'fr-CH', 'de', 'more');

        $this->assertStringContainsString('[fr-CH] Hallo', $html);
        $this->assertSame(
            ['POST translate/ai/file', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/translation', 'DELETE translate/ai/file/f1'],
            array_map(static fn ($c) => $c['method'].' '.str_replace('https://api.test/v1/', '', $c['url']), $fake->calls),
        );

        $parts = FakeSupertext::parseMultipart($fake->calls[0]['body']);
        $this->assertSame('fr-CH', $parts['target_lang']['body']);
        $this->assertSame('de', $parts['source_lang']['body']);
        $this->assertSame('more', $parts['politeness']['body']);
        // Exactly text/html: a charset suffix is rejected by Supertext with 415.
        $this->assertSame('text/html', $parts['file']['headers']['content-type']);
        $this->assertStringContainsString('filename="content.html"', $parts['file']['headers']['content-disposition']);
    }

    public function testOmitsOptionalFields(): void
    {
        $fake = new FakeSupertext();
        $this->client($fake)->translateHtml('<div data-st-id="a">x</div>', 'en');

        $this->assertStringNotContainsString('source_lang', $fake->calls[0]['body']);
        $this->assertStringNotContainsString('politeness', $fake->calls[0]['body']);
    }

    public function testSubmitsSeveralDocumentsBeforeWaiting(): void
    {
        $fake = new FakeSupertext();
        $client = $this->client($fake);
        $a = $client->submit('<div data-st-id="a">x</div>', 'de-CH');
        $b = $client->submit('<div data-st-id="a">x</div>', 'fr-CH');

        $this->assertStringContainsString('[de-CH] x', $client->collect($a));
        $this->assertStringContainsString('[fr-CH] x', $client->collect($b));
    }

    public static function statusProvider(): iterable
    {
        yield ['error', 'translation_error'];
        yield ['limit_exceeded', 'quota_exceeded'];
        yield ['deleted', 'file_deleted'];
    }

    #[DataProvider('statusProvider')]
    public function testMapsStatusAndStillDeletes(string $status, string $code): void
    {
        $fake = new FakeSupertext([$status]);

        try {
            $this->client($fake)->translateHtml('x', 'en');
            $this->fail('Expected an exception');
        } catch (SupertextException $e) {
            $this->assertSame($code, $e->errorCode);
        }

        $this->assertSame('DELETE', end($fake->calls)['method']);
    }

    public function testTimesOut(): void
    {
        $fake = new FakeSupertext(['translating']);
        $this->expectExceptionObject(new SupertextException('timeout', 'Timed out waiting for the Supertext translation to finish.'));
        $this->client($fake, timeoutMs: 5000)->translateHtml('x', 'en');
    }

    public static function httpProvider(): iterable
    {
        yield [401, 'authentication_failure'];
        yield [413, 'payload_too_large'];
        yield [429, 'too_many_requests'];
        yield [503, 'service_unavailable'];
        yield [418, 'unexpected_status'];
    }

    #[DataProvider('httpProvider')]
    public function testMapsHttpErrors(int $status, string $code): void
    {
        try {
            $this->client(new FakeSupertext(failStatus: $status))->submit('x', 'en');
            $this->fail('Expected an exception');
        } catch (SupertextException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public function testRefusesWithoutKey(): void
    {
        $fake = new FakeSupertext();

        try {
            $this->client($fake, '')->validateApiKey();
            $this->fail('Expected an exception');
        } catch (SupertextException $e) {
            $this->assertSame('missing_api_key', $e->errorCode);
        }

        $this->assertSame([], $fake->calls);
    }

    public function testFactoryResolvesEnvironmentAtRuntime(): void
    {
        $fake = new FakeSupertext();
        $http = $fake->client();

        $this->assertTrue(SupertextClient::create($http, ' test-key ', '', 'STAGING')->hasApiKey());
        $this->assertFalse(SupertextClient::create($http, null, null, null)->hasApiKey());

        SupertextClient::create($http, 'test-key', '', 'staging')->validateApiKey();
        $this->assertSame('https://api.staging.supertext.com/v1/features', $fake->calls[0]['url']);

        SupertextClient::create($http, 'test-key', 'https://proxy.example/v1', 'live')->validateApiKey();
        $this->assertSame('https://proxy.example/v1/features', $fake->calls[1]['url']);

        $this->expectException(\InvalidArgumentException::class);
        SupertextClient::create($http, 'k', '', 'prod');
    }
}
