<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

use Supertext\ContaoTranslation\Sdk\Client;
use Supertext\ContaoTranslation\Sdk\Supertext;
use Supertext\ContaoTranslation\Sdk\SupertextException as SdkException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bundle's access to the Supertext AI file translation API (v1), built on the
 * Supertext SDK (supertext/supertext-sdk), which handles the auth header, language
 * codes, the multipart upload, HTTP 429 retries, polling and deleting the file:
 *
 *   1. POST   translate/ai/file                   multipart HTML upload → { file_id }
 *   2. GET    translate/ai/file/{id}/status       poll until `done`
 *   3. GET    translate/ai/file/{id}/translation  translated HTML
 *   4. DELETE translate/ai/file/{id}              always, also after errors
 *
 * Several documents can be submitted first and collected afterwards, so translations
 * into several languages are processed by Supertext in parallel. Requests go through
 * Symfony's HTTP client (SymfonyHttpTransport). SDK errors become this bundle's
 * SupertextException with the same `errorCode`.
 */
class SupertextClient
{
    public const ENVIRONMENTS = [
        'live' => Supertext::LIVE_BASE_URL,
        'staging' => 'https://api.staging.supertext.com/v1/',
        'testing' => 'https://api.testing.supertext.com/v1/',
    ];

    /** Retries after HTTP 429 (done by the SDK). */
    public const RATE_LIMIT_RETRIES = Supertext::DEFAULT_MAX_RETRIES;

    private const USER_AGENT = 'Contao-Supertext-Translation';

    private readonly Client $client;

    /**
     * @param (\Closure(int): void)|null $sleep Tests: replaces waiting (milliseconds); time
     *                                         then only advances through these calls
     */
    public function __construct(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiKey,
        string $baseUrl = self::ENVIRONMENTS['live'],
        int $pollIntervalMs = 2000,
        int $timeoutMs = 180_000,
        \Closure|null $sleep = null,
    ) {
        $clock = null;
        $sdkSleep = null;

        if (null !== $sleep) {
            $elapsed = 0.0;
            $sdkSleep = static function (float $seconds) use ($sleep, &$elapsed): void {
                $elapsed += $seconds;
                $sleep((int) round($seconds * 1000));
            };
            $clock = static function () use (&$elapsed): float {
                return $elapsed;
            };
        }

        $this->client = new Client(
            $apiKey,
            baseUrl: $baseUrl,
            pollInterval: $pollIntervalMs / 1000,
            timeout: $timeoutMs / 1000,
            userAgent: self::USER_AGENT,
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
        #[\SensitiveParameter] string|null $apiKey,
        string|null $apiUrl,
        string|null $environment,
        int $pollIntervalMs = 2000,
        int $timeoutMs = 180_000,
    ): self {
        $environment = strtolower(trim((string) $environment)) ?: 'live';

        if (!isset(self::ENVIRONMENTS[$environment]) && '' === trim((string) $apiUrl)) {
            throw new \InvalidArgumentException(\sprintf('Unknown Supertext environment "%s"; use one of: %s.', $environment, implode(', ', array_keys(self::ENVIRONMENTS))));
        }

        $baseUrl = trim((string) $apiUrl) ?: self::ENVIRONMENTS[$environment];

        return new self($httpClient, trim((string) $apiKey), $baseUrl, $pollIntervalMs, $timeoutMs);
    }

    public function hasApiKey(): bool
    {
        return '' !== trim((string) preg_replace('/^\s*Supertext-Auth-Key(?:\s+|$)/i', '', $this->apiKey));
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
     * Uploads a document and returns the file id. The SDK sends the target with a region
     * (`de` → `de-DE`) and the source as a bare language (`de-CH` → `de`).
     *
     * @param 'default'|'less'|'more' $politeness
     */
    public function submit(string $html, string $targetLang, string $sourceLang = '', string $politeness = 'default'): string
    {
        return $this->call(fn (): string => $this->client->submit($html, $targetLang, '' !== $sourceLang ? $sourceLang : null, $politeness));
    }

    /**
     * Waits for a submitted document, downloads it and deletes it on the server.
     */
    public function collect(string $fileId): string
    {
        try {
            return $this->call(function () use ($fileId): string {
                $this->client->waitUntilDone($fileId);

                return $this->client->download($fileId);
            });
        } finally {
            $this->client->delete($fileId);
        }
    }

    /** Cost-free check that the API key is accepted. */
    public function validateApiKey(): void
    {
        $this->call(fn (): null => $this->client->validateApiKey());
    }

    /** Wait before retry `$attempt` after HTTP 429 (Retry-After, else 1/2/4/8 s plus jitter). */
    public static function retryDelayMs(int $attempt, string|null $retryAfter): int
    {
        return Supertext::retryDelayMs($attempt, $retryAfter);
    }

    /** Authorization header value; a pasted `Supertext-Auth-Key ` prefix is not doubled. */
    public static function authHeader(#[\SensitiveParameter] string $apiKey): string
    {
        return Supertext::authHeader($apiKey);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function call(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (SdkException $e) {
            $detail = (string) $e->detail;

            if (null !== $e->apiErrorCode && '' !== $e->apiErrorCode) {
                $detail = $e->apiErrorCode.('' !== $detail ? ': '.$detail : '');
            }

            throw new SupertextException($e->errorCode, $e->getMessage(), $e->status, $e, $detail);
        }
    }
}
