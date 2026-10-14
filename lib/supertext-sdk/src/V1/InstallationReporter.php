<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Sdk\V1;

use Supertext\ContaoTranslation\Sdk\Http\CurlTransport;
use Supertext\ContaoTranslation\Sdk\Http\HttpRequest;
use Supertext\ContaoTranslation\Sdk\Http\HttpTransport;
use Supertext\ContaoTranslation\Sdk\Supertext;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the callback ping
 * (spec/reporting.json).
 *
 * The reporter keeps no state of its own: the plugin loads its ReporterState, passes it in, and stores
 * the state it gets back (ReporterResult::$state). No method throws: the center is optional, and a
 * failed report is simply tried again an hour later.
 *
 * The key is never sent to the center, only whether one is configured and how the check went.
 */
final class InstallationReporter
{
    public const DEFAULT_REPORT_URL = 'https://commandocenter-production.up.railway.app/api/v1/report';

    /** Seconds a report may take. */
    public const REPORT_TIMEOUT = 3;

    /** Seconds the key check waits for Supertext. */
    public const KEY_CHECK_TIMEOUT = ApiKeyCheck::TIMEOUT;

    /** Seconds without a new report after a failed one (except reportCheck()). */
    public const FAILURE_BACKOFF = 3600;

    public const EVENT_INSTALLED = 'installed';
    public const EVENT_SETTINGS_SAVED = 'settings_saved';
    public const EVENT_UPGRADED = 'upgraded';
    public const EVENT_API_KEY_CHECK = 'api_key_check';
    public const EVENT_CHECK = 'check';

    private const CHALLENGE = '/^[A-Za-z0-9_-]{16,64}$/D';

    /** The URL reports are sent to. */
    public readonly string $reportUrl;

    private readonly string $runtimeName;
    private readonly ?string $runtimeVersion;

    /** @var callable(float): void|null */
    private $sleep;

    /** @var callable(): float */
    private $now;

    /**
     * @param bool                          $enabled        the "Send version information to Supertext" setting combined with the environment: see reportsEnabled()
     * @param string                        $siteUrl        base URL of the installation, as the system knows it
     * @param string                        $pluginName     the plugin's GitHub repository name, e.g. "Contao-Supertext-Translation"
     * @param string                        $pluginVersion  from the plugin's official version source
     * @param string                        $systemName     the CMS, PIM, shop or application, in its own spelling
     * @param string|null                   $systemVersion  its version, if known
     * @param string|null                   $runtimeName    default "PHP"
     * @param string|null                   $runtimeVersion default PHP_VERSION (when $runtimeName is null)
     * @param string|null                   $sdkVersion     default Supertext::SDK_VERSION
     * @param string|null                   $callbackUrl    the plugin's public ping endpoint; null for hosted apps without one
     * @param string|null                   $reportUrl      default SUPERTEXT_REPORT_URL from the environment, else DEFAULT_REPORT_URL
     * @param HttpTransport|null            $transport      HTTP for the report and the key check; default CurlTransport
     * @param string                        $apiBaseUrl     Supertext API base URL for the key check
     * @param (callable(float): void)|null  $sleep          for the key check's 429 retries (for tests)
     * @param (callable(): float)|null      $now            wall-clock Unix time in seconds (for tests)
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $siteUrl,
        private readonly string $pluginName,
        private readonly string $pluginVersion,
        private readonly string $systemName,
        private readonly ?string $systemVersion = null,
        ?string $runtimeName = null,
        ?string $runtimeVersion = null,
        private readonly ?string $sdkVersion = null,
        private readonly ?string $callbackUrl = null,
        ?string $reportUrl = null,
        private readonly ?HttpTransport $transport = null,
        private readonly string $apiBaseUrl = Protocol::LIVE_BASE_URL,
        ?callable $sleep = null,
        ?callable $now = null,
    ) {
        if ($runtimeName === null || $runtimeName === '') {
            $this->runtimeName = 'PHP';
            $this->runtimeVersion = $runtimeVersion ?? PHP_VERSION;
        } else {
            $this->runtimeName = $runtimeName;
            $this->runtimeVersion = $runtimeVersion;
        }
        $env = getenv('SUPERTEXT_REPORT_URL');
        $this->reportUrl = match (true) {
            $reportUrl !== null && trim($reportUrl) !== '' => trim($reportUrl),
            \is_string($env) && trim($env) !== '' => trim($env),
            default => self::DEFAULT_REPORT_URL,
        };
        $this->sleep = $sleep;
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    /**
     * Whether version reports are on: SUPERTEXT_VERSION_REPORTS (0/false/off/no, 1/true/on/yes) wins over
     * the setting; the setting defaults to on.
     *
     * @param bool|null   $setting the plugin's setting; null when not set
     * @param string|null $env     the variable's value; when omitted, read from the environment
     */
    public static function reportsEnabled(?bool $setting = null, ?string $env = null): bool
    {
        if (\func_num_args() < 2) {
            $value = getenv('SUPERTEXT_VERSION_REPORTS');
            $env = \is_string($value) ? $value : null;
        }
        $value = strtolower(trim($env ?? ''));
        if (\in_array($value, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        if (\in_array($value, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        return $setting ?? true;
    }

    /**
     * The answer of the plugin's public ping endpoint (`GET <callback>?challenge=<c>`): 200 with
     * {"ok":true,"plugin","challenge"} for a valid challenge, 400 otherwise, 404 while reporting is off.
     * Nothing a caller sends is echoed unless it matches the challenge pattern.
     *
     * @param string|null $challenge the `challenge` query parameter
     * @param string      $plugin    the plugin's GitHub repository name
     * @param bool        $enabled   reportsEnabled()
     */
    public static function pingResponse(?string $challenge, string $plugin, bool $enabled): PingResponse
    {
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];
        if (!$enabled) {
            return new PingResponse(404, $headers, '{"ok":false}');
        }
        if ($challenge === null || preg_match(self::CHALLENGE, $challenge) !== 1) {
            return new PingResponse(400, $headers, '{"ok":false}');
        }
        $body = json_encode(['ok' => true, 'plugin' => $plugin, 'challenge' => $challenge], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new PingResponse(200, $headers, $body === false ? '{"ok":false}' : $body);
    }

    /** "<plugin>|<system>|<runtime>" versions (missing parts empty). */
    public function versions(): string
    {
        return implode('|', [$this->pluginVersion, $this->systemVersion ?? '', $this->runtimeVersion ?? '']);
    }

    /** After the plugin's settings were saved: checks the key, reports `installed` or `settings_saved`. */
    public function afterSettingsSaved(?ReporterState $state, #[\SensitiveParameter] string $apiKey): ReporterResult
    {
        $state ??= ReporterState::empty();
        $current = self::withCheck($state, $this->check($apiKey));

        return $this->reportIfDue($current, $current->reportedVersions === null ? self::EVENT_INSTALLED : self::EVENT_SETTINGS_SAVED);
    }

    /** On back-end requests that run the plugin's code: checks a changed key, reports what changed. */
    public function onRequest(?ReporterState $state, #[\SensitiveParameter] string $apiKey): ReporterResult
    {
        $current = $state ?? ReporterState::empty();
        if ($current->keyCheck === null || $current->keyCheck->fingerprint !== Protocol::apiKeyFingerprint($apiKey)) {
            $current = self::withCheck($current, $this->check($apiKey));
        }

        return $this->reportIfDue($current, $this->dueEvent($current));
    }

    /** A translation was rejected with 401/403: the key is invalid (no request to Supertext). */
    public function authenticationFailed(?ReporterState $state, #[\SensitiveParameter] string $apiKey, int $httpStatus = 401): ReporterResult
    {
        $check = new ApiKeyCheck(ApiKeyCheck::INVALID, $this->timestamp(), $httpStatus, null, Protocol::apiKeyFingerprint($apiKey));
        $current = self::withCheck($state ?? ReporterState::empty(), $check);

        return $this->reportIfDue($current, $this->dueEvent($current));
    }

    /** The "Test connection" button: checks the key, reports a change. */
    public function testConnection(?ReporterState $state, #[\SensitiveParameter] string $apiKey): ReporterResult
    {
        $current = self::withCheck($state ?? ReporterState::empty(), $this->check($apiKey));

        return $this->reportIfDue($current, $this->dueEvent($current));
    }

    /** Sends a `check` report now (commands, tests), even after a recent failure. */
    public function reportCheck(?ReporterState $state): ReporterResult
    {
        return $this->send($state ?? ReporterState::empty(), self::EVENT_CHECK);
    }

    /**
     * The report body for an event and state.
     *
     * @return array<string, mixed>
     */
    public function buildReport(string $event, ReporterState $state): array
    {
        $body = [
            'schema' => 1,
            'event' => $event,
            'site_url' => $this->siteUrl,
            'plugin' => ['name' => $this->pluginName, 'version' => $this->pluginVersion],
            'system' => self::nameVersion($this->systemName, $this->systemVersion),
            'runtime' => self::nameVersion($this->runtimeName, $this->runtimeVersion),
            'sdk_version' => $this->sdkVersion ?? Supertext::SDK_VERSION,
        ];
        $check = $state->keyCheck;
        if ($check !== null) {
            $body['api_key_configured'] = $check->fingerprint !== '';
            $c = ['status' => $check->status, 'checked_at' => $check->checkedAt];
            if ($check->httpStatus !== null) {
                $c['http_status'] = $check->httpStatus;
            }
            if ($check->errorCode !== null) {
                $c['error_code'] = $check->errorCode;
            }
            $body['api_key_check'] = $c;
        }
        if ($this->callbackUrl !== null && $this->callbackUrl !== '') {
            $body['callback'] = ['url' => $this->callbackUrl];
        }

        return $body;
    }

    private function dueEvent(ReporterState $state): ?string
    {
        if ($state->reportedVersions === null) {
            return self::EVENT_INSTALLED;
        }
        if ($state->reportedVersions !== $this->versions()) {
            return self::EVENT_UPGRADED;
        }
        if ($state->keyCheck !== null && $state->keyCheck->status !== $state->reportedKeyStatus) {
            return self::EVENT_API_KEY_CHECK;
        }

        return null;
    }

    private function check(#[\SensitiveParameter] string $apiKey): ApiKeyCheck
    {
        $fingerprint = Protocol::apiKeyFingerprint($apiKey);
        if ($fingerprint === '') {
            return new ApiKeyCheck(ApiKeyCheck::MISSING, $this->timestamp(), null, null, '');
        }
        try {
            $client = new Client(
                $apiKey,
                baseUrl: $this->apiBaseUrl,
                userAgent: $this->pluginName . '/' . $this->pluginVersion,
                transport: $this->transport,
                sleep: $this->sleep,
                now: $this->now,
            );

            return $client->checkApiKey();
        } catch (\Throwable) {
            // E.g. no ext-curl and no transport.
            return new ApiKeyCheck(ApiKeyCheck::ERROR, $this->timestamp(), null, null, $fingerprint);
        }
    }

    private function reportIfDue(ReporterState $state, ?string $event): ReporterResult
    {
        if ($event === null || !$this->enabled) {
            return new ReporterResult($state, $state->keyCheck, null, false);
        }
        if ($state->lastFailureAt !== null) {
            $failed = strtotime($state->lastFailureAt);
            if ($failed !== false && ($this->now)() - $failed < self::FAILURE_BACKOFF) {
                return new ReporterResult($state, $state->keyCheck, null, false);
            }
        }

        return $this->send($state, $event);
    }

    private function send(ReporterState $state, string $event): ReporterResult
    {
        if (!$this->enabled) {
            return new ReporterResult($state, $state->keyCheck, null, false);
        }
        $ok = false;
        try {
            $transport = $this->transport ?? new CurlTransport();
            $body = json_encode($this->buildReport($event, $state), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $response = $transport->send(new HttpRequest('POST', $this->reportUrl, [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'supertext-sdk-php ' . $this->pluginName . '/' . $this->pluginVersion,
            ], $body, (float) self::REPORT_TIMEOUT));
            $ok = $response->status >= 200 && $response->status < 300;
        } catch (\Throwable) {
            // Never fails the caller: the center is optional.
            $ok = false;
        }
        $next = $ok
            ? new ReporterState($this->versions(), $state->keyCheck->status ?? $state->reportedKeyStatus, null, $state->keyCheck)
            : new ReporterState($state->reportedVersions, $state->reportedKeyStatus, $this->timestamp(), $state->keyCheck);

        return new ReporterResult($next, $next->keyCheck, $event, $ok);
    }

    private static function withCheck(ReporterState $state, ApiKeyCheck $check): ReporterState
    {
        return new ReporterState($state->reportedVersions, $state->reportedKeyStatus, $state->lastFailureAt, $check);
    }

    private function timestamp(): string
    {
        return ApiKeyCheck::timestamp(($this->now)());
    }

    /** @return array{name: string, version?: string} */
    private static function nameVersion(string $name, ?string $version): array
    {
        return $version !== null && $version !== '' ? ['name' => $name, 'version' => $version] : ['name' => $name];
    }
}
