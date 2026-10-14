<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\Controller\PingController;
use Supertext\ContaoTranslation\Reporting\Reporting;
use Supertext\ContaoTranslation\Reporting\StateStore;
use Supertext\ContaoTranslation\Sdk\V1\ReporterState;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The bundle's side of the Commando Center: setting, state, when reports are sent, the
 * ping answer. Supertext and the center are a MockHttpClient; never the real services.
 */
final class ReportingTest extends TestCase
{
    private const CENTER = 'https://center.test/api/v1/report';

    private const KEY = 'secret-test-key';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $calls = [];

    private int $featuresStatus = 200;

    private int $centerStatus = 202;

    private function store(): StateStore
    {
        return new class implements StateStore {
            public string|null $json = null;

            public bool $broken = false;

            public function load(): ReporterState
            {
                if ($this->broken) {
                    throw new \RuntimeException('Table tl_supertext_state does not exist');
                }

                return ReporterState::from($this->json);
            }

            public function save(ReporterState $state): void
            {
                $this->json = json_encode($state, JSON_THROW_ON_ERROR);
            }
        };
    }

    private function reporting(StateStore $store, string|null $key = self::KEY, bool|null $setting = null, string|null $env = null): Reporting
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            if (self::CENTER === $url) {
                return new MockResponse('{"ok":true}', ['http_code' => $this->centerStatus]);
            }

            if (str_ends_with($url, '/features')) {
                return new MockResponse('{"supported_features":[]}', ['http_code' => $this->featuresStatus]);
            }

            return new MockResponse('unexpected', ['http_code' => 500]);
        });

        return new Reporting($store, $http, $key, 'https://api.test/v1/', 'live', $setting, $env, self::CENTER, now: static fn (): float => 1_792_000_000.0);
    }

    private static function request(): Request
    {
        return Request::create('https://www.example.ch/contao/supertext/translate/12');
    }

    /** @return list<array<string, mixed>> */
    private function reports(): array
    {
        return array_values(array_map(
            static fn (array $c): array => json_decode($c['body'], true, 16, JSON_THROW_ON_ERROR),
            array_filter($this->calls, static fn (array $c): bool => self::CENTER === $c['url']),
        ));
    }

    private function featureCalls(): int
    {
        return \count(array_filter($this->calls, static fn (array $c): bool => str_ends_with($c['url'], '/features')));
    }

    public function testSettingAndEnvironment(): void
    {
        $store = $this->store();

        $this->assertTrue($this->reporting($store)->enabled(), 'on by default');
        $this->assertFalse($this->reporting($store, setting: false)->enabled());
        $this->assertFalse($this->reporting($store, setting: true, env: '0')->enabled(), 'SUPERTEXT_VERSION_REPORTS=0 wins');
        $this->assertTrue($this->reporting($store, setting: false, env: 'on')->enabled());
        $this->assertFalse($this->reporting($store, setting: false, env: '')->enabled(), 'empty variable: the setting');
        $this->assertTrue($this->reporting($store, env: 'no')->decidedByEnvironment());
        $this->assertFalse($this->reporting($store, env: '')->decidedByEnvironment());
        $this->assertFalse($this->reporting($store, env: 'maybe')->decidedByEnvironment());
    }

    public function testFirstRequestChecksTheKeyAndReportsInstalled(): void
    {
        $store = $this->store();
        $result = $this->reporting($store)->run(Reporting::ON_REQUEST, self::request());

        $this->assertNotNull($result);
        $this->assertSame('installed', $result->event);
        $this->assertTrue($result->sent);
        $this->assertSame('ok', $result->check?->status);
        $this->assertSame(1, $this->featureCalls());
        $this->assertSame('https://api.test/v1/features', $this->calls[0]['url'], 'the configured API');

        $report = $this->reports()[0];
        $this->assertSame('installed', $report['event']);
        $this->assertSame('https://www.example.ch', $report['site_url']);
        $this->assertSame('Contao-Supertext-Translation', $report['plugin']['name']);
        $this->assertSame('Contao', $report['system']['name']);
        $this->assertSame('PHP', $report['runtime']['name']);
        $this->assertTrue($report['api_key_configured']);
        $this->assertSame('ok', $report['api_key_check']['status']);
        $this->assertSame('https://www.example.ch/_supertext/ping', $report['callback']['url']);

        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString(self::KEY, $call['body'], 'the key is never sent to the center');
        }

        $this->assertStringNotContainsString(self::KEY, (string) $store->json, 'the key is never stored');
        $this->assertSame('ok', ReporterState::from($store->json)->keyCheck?->status);
    }

    public function testNothingIsSentWhenNothingChanged(): void
    {
        $store = $this->store();
        $this->reporting($store)->run(Reporting::ON_REQUEST, self::request());
        $this->calls = [];

        $result = $this->reporting($store)->run(Reporting::ON_REQUEST, self::request());

        $this->assertNull($result?->event);
        $this->assertSame([], $this->calls, 'no key check (same key) and no report');
    }

    public function testChangedKeyIsCheckedAgainAndReported(): void
    {
        $store = $this->store();
        $this->reporting($store)->run(Reporting::ON_REQUEST, self::request());
        $this->calls = [];
        $this->featuresStatus = 401;

        $result = $this->reporting($store, 'another-key')->run(Reporting::ON_REQUEST, self::request());

        $this->assertSame('api_key_check', $result?->event);
        $this->assertSame('invalid', $result?->check?->status);
        $this->assertSame(1, $this->featureCalls());
    }

    public function testRejectedKeyDuringTranslationIsReportedOnce(): void
    {
        $store = $this->store();
        $this->reporting($store)->run(Reporting::ON_REQUEST, self::request());
        $this->calls = [];

        $first = $this->reporting($store)->run(Reporting::AUTHENTICATION_FAILED, self::request(), 403);
        $second = $this->reporting($store)->run(Reporting::AUTHENTICATION_FAILED, self::request(), 403);

        $this->assertSame('api_key_check', $first?->event);
        $this->assertSame('invalid', $first?->check?->status);
        $this->assertSame(403, $first?->check?->httpStatus);
        $this->assertNull($second?->event, 'only when the status changes');
        $this->assertSame(0, $this->featureCalls(), 'no request to Supertext');
        $this->assertCount(1, $this->reports());
    }

    public function testMissingKeyIsCheckedWithoutARequest(): void
    {
        $store = $this->store();
        $result = $this->reporting($store, '')->run(Reporting::ON_REQUEST, self::request());

        $this->assertSame('missing', $result?->check?->status);
        $this->assertSame(0, $this->featureCalls());
        $this->assertFalse($this->reports()[0]['api_key_configured']);
    }

    public function testOffMeansNothingIsSentButTheKeyIsStillChecked(): void
    {
        $store = $this->store();
        $result = $this->reporting($store, env: '0')->run(Reporting::ON_REQUEST, self::request());

        $this->assertSame('ok', $result?->check?->status);
        $this->assertSame([], $this->reports());
        $this->assertSame(1, $this->featureCalls());
    }

    public function testFailedReportIsNotRetriedForAnHour(): void
    {
        $store = $this->store();
        $this->centerStatus = 503;
        $first = $this->reporting($store)->run(Reporting::ON_REQUEST, self::request());
        $this->centerStatus = 202;
        $second = $this->reporting($store)->run(Reporting::ON_REQUEST, self::request());

        $this->assertFalse($first?->sent);
        $this->assertNull($second?->event);
        $this->assertCount(1, $this->reports());
    }

    public function testBrokenStorageNeverThrows(): void
    {
        $store = $this->store();
        $store->broken = true;
        $reporting = $this->reporting($store);

        $this->assertNull($reporting->run(Reporting::ON_REQUEST, self::request()));
        $this->assertNull($reporting->lastCheck());
        $this->assertSame([], $this->calls, 'nothing sent without state, so nothing is sent twice');
    }

    public function testQueuedActionsRunAfterTheResponse(): void
    {
        $store = $this->store();
        $reporting = $this->reporting($store);
        $reporting->queue(Reporting::ON_REQUEST, self::request());
        $reporting->queue(Reporting::AUTHENTICATION_FAILED, self::request(), 401);
        $reporting->queue(Reporting::ON_REQUEST, self::request());

        $this->assertSame([], $this->calls, 'nothing during the request');

        $reporting->onKernelTerminate();

        $this->assertSame('invalid', $reporting->lastCheck()?->status, 'the rejected key replaces the request report');
        $this->assertSame(0, $this->featureCalls());
        $this->assertSame(['installed'], array_column($this->reports(), 'event'));

        $this->calls = [];
        $reporting->onKernelTerminate();
        $this->assertSame([], $this->calls, 'the queue is empty afterwards');
    }

    public function testPing(): void
    {
        $ping = new PingController($this->reporting($this->store()));

        $ok = $ping(Request::create('/_supertext/ping?challenge=abcdefghijklmnop1234'));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('{"ok":true,"plugin":"Contao-Supertext-Translation","challenge":"abcdefghijklmnop1234"}', $ok->getContent());
        $this->assertSame('application/json', $ok->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $ok->headers->get('Cache-Control'));

        $this->assertSame(400, $ping(Request::create('/_supertext/ping?challenge=<script>'))->getStatusCode());
        $this->assertSame(400, $ping(Request::create('/_supertext/ping'))->getStatusCode());

        $off = new PingController($this->reporting($this->store(), env: 'false'));
        $this->assertSame(404, $off(Request::create('/_supertext/ping?challenge=abcdefghijklmnop1234'))->getStatusCode());
        $this->assertSame([], $this->calls, 'the ping sends nothing');
    }

    public function testVersions(): void
    {
        $this->assertNotSame('', Reporting::pluginVersion());
        $this->assertSame('https://www.example.ch/sub', Reporting::siteUrl(Request::create('https://www.example.ch/sub/contao', server: ['SCRIPT_FILENAME' => '/var/www/sub/index.php', 'SCRIPT_NAME' => '/sub/index.php', 'PHP_SELF' => '/sub/index.php'])));
    }
}
