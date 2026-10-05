<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Supertext AI file translation API (v1), same protocol as the WordPress and Payload
 * plugins:
 *
 *   1. POST   translate/ai/file                   multipart HTML upload → { file_id }
 *   2. GET    translate/ai/file/{id}/status       poll until `done`
 *   3. GET    translate/ai/file/{id}/translation  translated HTML
 *   4. DELETE translate/ai/file/{id}              best-effort cleanup (expires after 24 h)
 *
 * Several documents can be submitted first and awaited afterwards, so translations into
 * several languages are processed by Supertext in parallel.
 */
class SupertextClient
{
    public const ENVIRONMENTS = [
        'live' => 'https://api.supertext.com/v1/',
        'staging' => 'https://api.staging.supertext.com/v1/',
        'testing' => 'https://api.testing.supertext.com/v1/',
    ];

    private readonly string $baseUrl;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        string $baseUrl = self::ENVIRONMENTS['live'],
        private readonly int $pollIntervalMs = 2000,
        private readonly int $timeoutMs = 180_000,
        \Closure|null $sleep = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/').'/';
        $this->sleep = $sleep ?? static fn (int $ms) => usleep($ms * 1000);
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
        $environment = strtolower(trim((string) $environment)) ?: 'live';

        if (!isset(self::ENVIRONMENTS[$environment]) && '' === trim((string) $apiUrl)) {
            throw new \InvalidArgumentException(\sprintf('Unknown Supertext environment "%s"; use one of: %s.', $environment, implode(', ', array_keys(self::ENVIRONMENTS))));
        }

        $baseUrl = trim((string) $apiUrl) ?: self::ENVIRONMENTS[$environment];

        return new self($httpClient, trim((string) $apiKey), $baseUrl, $pollIntervalMs, $timeoutMs);
    }

    public function hasApiKey(): bool
    {
        return '' !== $this->apiKey;
    }

    /**
     * Full round trip for one document.
     *
     * @param 'default'|'less'|'more' $politeness
     */
    public function translateHtml(string $html, string $targetLang, string $sourceLang = '', string $politeness = 'default'): string
    {
        $fileId = $this->submit($html, $targetLang, $sourceLang, $politeness);

        return $this->collect($fileId);
    }

    /**
     * Uploads a document and returns the file id.
     *
     * @param 'default'|'less'|'more' $politeness
     */
    public function submit(string $html, string $targetLang, string $sourceLang = '', string $politeness = 'default'): string
    {
        $fields = ['target_lang' => $targetLang];

        if ('' !== $sourceLang) {
            $fields['source_lang'] = $sourceLang;
        }

        if ('default' !== $politeness) {
            $fields['politeness'] = $politeness;
        }

        // Supertext matches the part's Content-Type against an allow-list verbatim: it
        // must be exactly "text/html" (a charset suffix is rejected with 415). The
        // document declares UTF-8 via <meta charset>.
        $fields['file'] = new DataPart($html, 'content.html', 'text/html');
        $form = new FormDataPart($fields);

        $response = $this->request('POST', 'translate/ai/file', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);

        $data = json_decode($response->getContent(false), true);

        if (!\is_array($data) || !\is_string($data['file_id'] ?? null) || '' === $data['file_id']) {
            throw new SupertextException('no_file_id', 'Supertext did not return a file id.');
        }

        return $data['file_id'];
    }

    /**
     * Waits for a submitted document, downloads it and deletes it on the server.
     */
    public function collect(string $fileId): string
    {
        try {
            $this->waitUntilDone($fileId);

            return $this->download($fileId);
        } finally {
            $this->delete($fileId);
        }
    }

    /** Cost-free check that the API key is accepted. */
    public function validateApiKey(): void
    {
        $this->request('GET', 'features');
    }

    private function waitUntilDone(string $fileId): void
    {
        $waited = 0;

        while (true) {
            $response = $this->request('GET', 'translate/ai/file/'.rawurlencode($fileId).'/status');
            $data = json_decode($response->getContent(false), true);

            switch (\is_array($data) ? ($data['status'] ?? null) : null) {
                case 'done':
                    return;

                case 'error':
                    throw new SupertextException('translation_error', 'Supertext failed to translate the document.');

                case 'limit_exceeded':
                    throw new SupertextException('quota_exceeded', 'Your Supertext translation limit is exceeded. Please upgrade your subscription.');

                case 'deleted':
                    throw new SupertextException('file_deleted', 'The Supertext translation file was deleted before it could be downloaded.');
            }

            // `translating` (or an unknown transient status): wait and retry.
            if ($waited + $this->pollIntervalMs >= $this->timeoutMs) {
                throw new SupertextException('timeout', 'Timed out waiting for the Supertext translation to finish.');
            }

            ($this->sleep)($this->pollIntervalMs);
            $waited += $this->pollIntervalMs;
        }
    }

    private function download(string $fileId): string
    {
        $body = $this->request('GET', 'translate/ai/file/'.rawurlencode($fileId).'/translation')->getContent(false);

        if ('' === trim($body)) {
            throw new SupertextException('incomplete_response', 'The translated document was empty.');
        }

        return $body;
    }

    private function delete(string $fileId): void
    {
        try {
            $this->request('DELETE', 'translate/ai/file/'.rawurlencode($fileId));
        } catch (SupertextException) {
            // Best effort: files expire after 24 hours anyway.
        }
    }

    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if ('' === $this->apiKey) {
            throw new SupertextException('missing_api_key', 'No Supertext API key is configured.');
        }

        $options['headers'] = [
            ...($options['headers'] ?? []),
            'Authorization: Supertext-Auth-Key '.$this->apiKey,
            'Accept: application/json',
        ];
        $options['timeout'] ??= 30;

        try {
            $response = $this->httpClient->request($method, $this->baseUrl.$path, $options);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new SupertextException('transport_error', 'Could not reach Supertext: '.$e->getMessage(), null, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw SupertextException::fromStatus($status, $response->getContent(false));
        }

        return $response;
    }
}
