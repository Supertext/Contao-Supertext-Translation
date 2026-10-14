<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

use Supertext\ContaoTranslation\Sdk\V1\Client;
use Supertext\ContaoTranslation\Sdk\V1\SupertextException as SdkException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bundle's access to the Supertext AI file translation API (v1), built on the shared
 * Supertext PHP SDK (lib/supertext-sdk, namespace Supertext\ContaoTranslation\Sdk), which does the
 * protocol: multipart upload, polling, download, cleanup, the auth header and HTTP 429
 * retries. Language codes are resolved before (Translation\Languages): the client sends
 * them as given.
 *
 *   1. POST   translate/ai/file                   multipart HTML upload → { file_id }
 *   2. GET    translate/ai/file/{id}/status       poll until `done`
 *   3. GET    translate/ai/file/{id}/translation  translated HTML
 *   4. DELETE translate/ai/file/{id}              cleanup (files expire after 24 h anyway)
 *
 * Several documents can be submitted first and collected afterwards, so translations
 * into several languages are processed by Supertext in parallel. Requests go through
 * Contao's Symfony HttpClient. SDK errors come out as this bundle's SupertextException
 * (same error codes).
 */
class SupertextClient
{
    public const ENVIRONMENTS = [
        'live' => 'https://api.supertext.com/v1/',
        'staging' => 'https://api.staging.supertext.com/v1/',
        'testing' => 'https://api.testing.supertext.com/v1/',
    ];

    private readonly Client $sdk;

    private readonly bool $hasApiKey;

    /**
     * @param \Closure(int): void|null $sleep sleeps the given milliseconds (tests)
     */
    public function __construct(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl = self::ENVIRONMENTS['live'],
        int $pollIntervalMs = 2000,
        int $timeoutMs = 180_000,
        \Closure|null $sleep = null,
    ) {
        $this->hasApiKey = '' !== trim((string) preg_replace('/^\s*Supertext-Auth-Key(?:\s+|$)/i', '', $apiKey));

        // Tests inject $sleep (returns at once): the SDK's clock then counts the slept
        // time, so its timeout works without waiting in real time.
        $elapsed = 0.0;
        $sdkSleep = null === $sleep ? null : static function (float $seconds) use ($sleep, &$elapsed): void {
            $elapsed += $seconds;
            $sleep((int) round($seconds * 1000));
        };
        $clock = null === $sleep ? null : static function () use (&$elapsed): float {
            return $elapsed;
        };

        $this->sdk = new Client(
            $apiKey,
            baseUrl: $baseUrl,
            pollInterval: $pollIntervalMs / 1000,
            timeout: $timeoutMs / 1000,
            userAgent: 'Contao-Supertext-Translation',
            transport: new SymfonyHttpTransport($httpClient),
            sleep: $sdkSleep,
            clock: $clock,
        );
    }

    /**
     * Factory for the service container: `$apiUrl` (explicit URL) wins over
     * `$environment`; both may come from environment variables, so they are resolved
     * here at runtime.
     */
    public static function create(
        HttpClientInterface $httpClient,
        string|null $apiKey,
        string|null $apiUrl,
        string|null $environment,
        int $pollIntervalMs = 2000,
        int $timeoutMs = 180_000,
    ): self {
        return new self($httpClient, trim((string) $apiKey), self::baseUrl($apiUrl, $environment), $pollIntervalMs, $timeoutMs);
    }

    /**
     * The API base URL: `$apiUrl` (explicit URL) wins over `$environment` (live, staging, testing).
     *
     * @throws \InvalidArgumentException for an unknown environment without an explicit URL
     */
    public static function baseUrl(string|null $apiUrl, string|null $environment): string
    {
        $environment = strtolower(trim((string) $environment)) ?: 'live';

        if (!isset(self::ENVIRONMENTS[$environment]) && '' === trim((string) $apiUrl)) {
            throw new \InvalidArgumentException(\sprintf('Unknown Supertext environment "%s"; use one of: %s.', $environment, implode(', ', array_keys(self::ENVIRONMENTS))));
        }

        return trim((string) $apiUrl) ?: self::ENVIRONMENTS[$environment];
    }

    public function hasApiKey(): bool
    {
        return $this->hasApiKey;
    }

    /**
     * Full round trip for one document.
     *
     * @param 'default'|'less'|'more' $politeness
     */
    public function translateHtml(string $html, string $targetLang, string $sourceLang = '', string $politeness = 'default'): string
    {
        return $this->collect($this->submit($html, $targetLang, $sourceLang, $politeness));
    }

    /**
     * Uploads a document and returns the file id. Language codes are sent as given
     * (resolve them with Translation\Languages first).
     *
     * @param 'default'|'less'|'more' $politeness
     */
    public function submit(string $html, string $targetLang, string $sourceLang = '', string $politeness = 'default'): string
    {
        return $this->call(fn () => $this->sdk->submit($html, $targetLang, '' === $sourceLang ? null : $sourceLang, $politeness));
    }

    /**
     * Waits for a submitted document, downloads it and deletes it on the server.
     */
    public function collect(string $fileId): string
    {
        try {
            return $this->call(function () use ($fileId): string {
                $this->sdk->waitUntilDone($fileId);

                return $this->sdk->download($fileId);
            });
        } finally {
            $this->sdk->delete($fileId);
        }
    }

    /** Cost-free check that the API key is accepted. */
    public function validateApiKey(): void
    {
        $this->call(fn () => $this->sdk->validateApiKey());
    }

    /**
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }
}
