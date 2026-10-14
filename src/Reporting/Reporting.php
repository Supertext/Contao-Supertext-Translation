<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Reporting;

use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use Supertext\ContaoTranslation\Sdk\V1\ApiKeyCheck;
use Supertext\ContaoTranslation\Sdk\V1\InstallationReporter;
use Supertext\ContaoTranslation\Sdk\V1\PingResponse;
use Supertext\ContaoTranslation\Sdk\V1\ReporterResult;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Supertext\ContaoTranslation\Supertext\SymfonyHttpTransport;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the
 * callback ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report protocol).
 * The protocol is the SDK's (InstallationReporter); this class only gives it what Contao
 * knows: the site URL, the Contao and bundle versions, and a place to keep its state.
 *
 * The bundle has no settings form (its settings are in config/config.yaml and the
 * environment), so there is no "settings saved": the key check runs when the key changed
 * (fingerprint), and after a 401/403 from Supertext. Reports run after the response
 * (kernel.terminate), so they never slow down or break the translate screen. Nothing here
 * throws.
 */
class Reporting
{
    /** The GitHub repository name (the center reads the latest release from it). */
    public const PLUGIN_NAME = 'Contao-Supertext-Translation';

    public const PACKAGE = 'supertext/contao-supertext-translation';

    public const PING_ROUTE = 'supertext_translation_ping';

    public const PING_PATH = '/_supertext/ping';

    public const ON_REQUEST = 'onRequest';

    public const AUTHENTICATION_FAILED = 'authenticationFailed';

    /** @var array<string, array{Request, int}> actions to run after the response */
    private array $pending = [];

    /**
     * @param bool|null          $setting      `version_reports` (null: not set, on)
     * @param string|null        $envReports   SUPERTEXT_VERSION_REPORTS (wins over the setting)
     * @param string|null        $reportUrl    SUPERTEXT_REPORT_URL (tests), else the SDK's default
     * @param \Closure(): float|null $now      wall-clock time (tests)
     */
    public function __construct(
        private readonly StateStore $store,
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string|null $apiKey,
        private readonly string|null $apiUrl = null,
        private readonly string|null $environment = null,
        private readonly bool|null $setting = null,
        private readonly string|null $envReports = null,
        private readonly string|null $reportUrl = null,
        private readonly UrlGeneratorInterface|null $router = null,
        private readonly LoggerInterface|null $logger = null,
        private readonly \Closure|null $now = null,
    ) {
    }

    /** "Send version information to Supertext": the setting, unless SUPERTEXT_VERSION_REPORTS says otherwise. */
    public function enabled(): bool
    {
        return InstallationReporter::reportsEnabled($this->setting, null === $this->envReports || '' === trim($this->envReports) ? null : $this->envReports);
    }

    /** Whether the environment variable decides (shown on the status box). */
    public function decidedByEnvironment(): bool
    {
        // The SDK lets a recognised value of the variable win over either setting.
        return InstallationReporter::reportsEnabled(true, $this->envReports ?? '') === InstallationReporter::reportsEnabled(false, $this->envReports ?? '');
    }

    /** The public ping route's answer. */
    public function ping(mixed $challenge): PingResponse
    {
        return InstallationReporter::pingResponse(\is_string($challenge) ? $challenge : null, self::PLUGIN_NAME, $this->enabled());
    }

    /** The site's base URL as Contao serves this request: scheme, host and base path. */
    public static function siteUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
    }

    public function callbackUrl(Request $request): string
    {
        try {
            if (null !== $this->router) {
                return $this->router->generate(self::PING_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        } catch (\Throwable) {
            // route not loaded (tests)
        }

        return self::siteUrl($request).self::PING_PATH;
    }

    /** The bundle's version from Composer (the Git tag it was installed from), e.g. "0.2.0" or "dev-main". */
    public static function pluginVersion(): string
    {
        return self::packageVersion(self::PACKAGE) ?? '0.0.0';
    }

    /** The installed Contao version (contao/core-bundle, or contao/contao in the monorepo). */
    public static function contaoVersion(): string|null
    {
        return self::packageVersion('contao/core-bundle') ?? self::packageVersion('contao/contao');
    }

    public function reporter(Request $request): InstallationReporter
    {
        return new InstallationReporter(
            enabled: $this->enabled(),
            siteUrl: self::siteUrl($request),
            pluginName: self::PLUGIN_NAME,
            pluginVersion: self::pluginVersion(),
            systemName: 'Contao',
            systemVersion: self::contaoVersion(),
            callbackUrl: $this->callbackUrl($request),
            reportUrl: null === $this->reportUrl || '' === trim($this->reportUrl) ? null : $this->reportUrl,
            transport: new SymfonyHttpTransport($this->httpClient),
            apiBaseUrl: SupertextClient::baseUrl($this->apiUrl, $this->environment),
            now: $this->now,
        );
    }

    /**
     * Runs one reporter action with the stored state and stores the result. Never throws;
     * null when nothing could run (e.g. the state table doesn't exist yet).
     */
    public function run(string $action, Request $request, int $httpStatus = 401): ReporterResult|null
    {
        try {
            $state = $this->store->load();
            $reporter = $this->reporter($request);
            $key = trim((string) $this->apiKey);
            $result = self::AUTHENTICATION_FAILED === $action
                ? $reporter->authenticationFailed($state, $key, $httpStatus)
                : $reporter->onRequest($state, $key);
            $this->store->save($result->state);

            if (null !== $result->event) {
                $this->logger?->debug(\sprintf('Supertext version report "%s": %s', $result->event, $result->sent ? 'sent' : 'not sent'));
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger?->debug('Supertext version report skipped: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Runs the action after the response has been sent (kernel.terminate). A rejected key
     * replaces a plain request report of the same request.
     */
    public function queue(string $action, Request $request, int $httpStatus = 401): void
    {
        if (self::ON_REQUEST === $action && isset($this->pending[self::AUTHENTICATION_FAILED])) {
            return;
        }

        if (self::AUTHENTICATION_FAILED === $action) {
            unset($this->pending[self::ON_REQUEST]);
        }

        $this->pending[$action] = [$request, $httpStatus];
    }

    /** kernel.terminate (tagged in config/services.php). */
    public function onKernelTerminate(TerminateEvent|null $event = null): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $action => [$request, $httpStatus]) {
            $this->run($action, $request, $httpStatus);
        }
    }

    /** The latest stored key check (for the translate screen), or null. */
    public function lastCheck(): ApiKeyCheck|null
    {
        try {
            return $this->store->load()->keyCheck;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function packageVersion(string $package): string|null
    {
        try {
            if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
                return null;
            }

            $version = InstalledVersions::getPrettyVersion($package);
        } catch (\Throwable) {
            return null;
        }

        return null === $version || '' === $version ? null : (string) preg_replace('/^v(?=\d)/', '', $version);
    }
}
