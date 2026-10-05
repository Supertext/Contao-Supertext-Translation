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

    public function testRetriesWhenRateLimited(): void
    {
        $fake = new FakeSupertext();
        $fake->rateLimited = 3;
        $waits = [];
        $client = new SupertextClient($fake->client(), 'test-key', 'https://api.test/v1', 1000, 180_000, static function (int $ms) use (&$waits): void { $waits[] = $ms; });

        $html = $client->translateHtml('<div data-st-id="a">Hallo</div>', 'it-CH', 'de');

        $this->assertStringContainsString('[it-CH] Hallo', $html);
        // The upload is sent again in full after each 429.
        $uploads = $fake->calls(static fn ($c) => 'POST' === $c['method']);
        $this->assertCount(4, $uploads);
        $this->assertSame($uploads[0]['body'], $uploads[3]['body']);
        $this->assertCount(3, $waits);
        $this->assertGreaterThanOrEqual(1000, $waits[0]);
        $this->assertGreaterThanOrEqual(4000, $waits[2]);
    }

    public function testGivesUpAfterFourRetries(): void
    {
        $fake = new FakeSupertext();
        $fake->rateLimited = 5;

        try {
            $this->client($fake)->translateHtml('<div data-st-id="a">Hallo</div>', 'it-CH');
            $this->fail('Expected a SupertextException.');
        } catch (SupertextException $e) {
            $this->assertSame('too_many_requests', $e->errorCode);
        }

        $this->assertCount(5, $fake->calls);
    }

    public function testRetryDelayUsesRetryAfter(): void
    {
        $this->assertSame(3000, SupertextClient::retryDelayMs(0, '3'));
        $this->assertSame(30_000, SupertextClient::retryDelayMs(0, '120'));
        $delay = SupertextClient::retryDelayMs(1, null);
        $this->assertTrue($delay >= 2000 && $delay <= 2250);
    }

    public function testAcceptsKeyWithPrefix(): void
    {
        $fake = new FakeSupertext();
        $html = $this->client($fake, '  Supertext-Auth-Key test-key ')->translateHtml('<div data-st-id="a">Hallo</div>', 'fr-CH');

        $this->assertStringContainsString('[fr-CH] Hallo', $html);
        $this->assertSame('Supertext-Auth-Key abc', SupertextClient::authHeader('abc'));
        $this->assertSame('Supertext-Auth-Key abc', SupertextClient::authHeader('supertext-auth-key abc'));
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
