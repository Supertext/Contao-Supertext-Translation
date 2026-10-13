<?php

declare(strict_types=1);

namespace Supertext\Sdk;

use Supertext\Sdk\Http\CurlTransport;
use Supertext\Sdk\Http\HttpRequest;
use Supertext\Sdk\Http\HttpResponse;
use Supertext\Sdk\Http\HttpTransport;

/**
 * Client for the Supertext AI file translation API v1.
 *
 * One translation is: upload an HTML document, poll its status, download the translation, delete the
 * file. translateTexts() packs keyed texts into such documents and reads them back.
 *
 * Language codes are fixed up here (target "de" → "de-DE", source "en-US" → "en"), so callers can pass
 * their CMS locales. Integrations with per-language overrides call LanguageCodes::target($code, $overrides)
 * first; that is idempotent.
 */
final class Client
{
    private const USER_AGENT = 'supertext-sdk-php';

    private readonly string $baseUrl;
    private readonly HttpTransport $transport;
    private readonly string $userAgent;

    /** @var callable(float): void */
    private $sleep;

    /** @var callable(): float */
    private $clock;

    /**
     * A blank key doesn't fail here: the first request throws `missing_api_key` without sending anything,
     * so a client can be created before the integration is configured.
     *
     * @param string                       $apiKey         with or without the "Supertext-Auth-Key " prefix
     * @param string                       $baseUrl        API base URL (a trailing slash is added)
     * @param float                        $pollInterval   seconds between status polls
     * @param float                        $timeout        seconds to wait for a translation, from the first poll
     * @param float                        $requestTimeout seconds per HTTP request
     * @param int                          $maxRetries     retries after HTTP 429
     * @param string|null                  $userAgent      appended to the User-Agent, e.g. "MyCms-Supertext/1.2.0"
     * @param HttpTransport|null           $transport      defaults to CurlTransport (needs ext-curl; pass your own transport to do without)
     * @param (callable(float): void)|null $sleep          sleeps the given seconds (for tests)
     * @param (callable(): float)|null     $clock          monotonic time in seconds (for tests)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        string $baseUrl = Supertext::LIVE_BASE_URL,
        private readonly float $pollInterval = 2.0,
        private readonly float $timeout = 180.0,
        private readonly float $requestTimeout = 30.0,
        private readonly int $maxRetries = Supertext::DEFAULT_MAX_RETRIES,
        ?string $userAgent = null,
        ?HttpTransport $transport = null,
        ?callable $sleep = null,
        ?callable $clock = null,
    ) {
        $baseUrl = trim($baseUrl);
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : Supertext::LIVE_BASE_URL, '/') . '/';
        $userAgent = $userAgent === null ? '' : trim($userAgent);
        $this->userAgent = $userAgent === '' ? self::USER_AGENT : self::USER_AGENT . ' ' . $userAgent;
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /** Keeps the key out of var_dump() and print_r(). */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'userAgent' => $this->userAgent];
    }

    /**
     * Cost-free check that the key is accepted.
     *
     * @throws SupertextException e.g. authentication_failure
     */
    public function validateApiKey(): void
    {
        $this->request('GET', 'features');
    }

    /**
     * Uploads an HTML document for translation and returns its file id.
     *
     * @param string      $targetLang any form ("de", "de_ch"); sent as a regional code
     * @param string|null $sourceLang any form ("en-GB"); sent as a bare language. Null or "": auto-detect.
     * @param string      $politeness Politeness::DEFAULT, MORE (formal) or LESS (informal)
     */
    public function submit(string $html, string $targetLang, ?string $sourceLang = null, string $politeness = Politeness::DEFAULT): string
    {
        // Fields in the order the API documents; the file comes last.
        $fields = ['target_lang' => LanguageCodes::target($targetLang)];
        $source = LanguageCodes::source($sourceLang ?? '');
        if ($source !== '') {
            $fields['source_lang'] = $source;
        }
        // Only "more" and "less" mean something; anything else (including "default") isn't sent.
        if ($politeness === Politeness::MORE || $politeness === Politeness::LESS) {
            $fields['politeness'] = $politeness;
        }

        $boundary = '----supertext-' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        // The part's Content-Type must be exactly "text/html": with a charset parameter the API answers 415.
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"content.html\"\r\n"
            . "Content-Type: text/html\r\n\r\n{$html}\r\n--{$boundary}--\r\n";

        $data = self::json($this->request('POST', 'translate/ai/file', $body, 'multipart/form-data; boundary=' . $boundary));
        $fileId = $data['file_id'] ?? null;
        if (!\is_string($fileId) && !\is_int($fileId)) {
            $fileId = '';
        }
        if ((string) $fileId === '') {
            throw new SupertextException(SupertextException::NO_FILE_ID);
        }

        return (string) $fileId;
    }

    /**
     * The file's status as the API reports it: "translating", "done", "error", "limit_exceeded", "deleted"
     * or anything the API adds later.
     */
    public function getStatus(string $fileId): string
    {
        $status = self::json($this->request('GET', self::filePath($fileId) . '/status'))['status'] ?? '';

        return \is_string($status) ? $status : '';
    }

    /**
     * Polls until the file is done.
     *
     * @throws SupertextException translation_error, quota_exceeded, file_deleted, timeout or an HTTP error
     */
    public function waitUntilDone(string $fileId): void
    {
        $start = ($this->clock)();
        while (true) {
            switch ($this->getStatus($fileId)) {
                case 'done':
                    return;
                case 'error':
                    throw new SupertextException(SupertextException::TRANSLATION_ERROR);
                case 'limit_exceeded':
                    throw new SupertextException(SupertextException::QUOTA_EXCEEDED);
                case 'deleted':
                    throw new SupertextException(SupertextException::FILE_DELETED);
            }
            // Unknown statuses ("translating", "queued", …) mean: not yet.
            if (($this->clock)() - $start + $this->pollInterval > $this->timeout) {
                throw new SupertextException(SupertextException::TIMEOUT);
            }
            ($this->sleep)($this->pollInterval);
        }
    }

    /**
     * The translated HTML.
     *
     * @throws SupertextException incomplete_response when the answer is empty
     */
    public function download(string $fileId): string
    {
        $body = $this->request('GET', self::filePath($fileId) . '/translation')->body;
        if (trim($body, " \t\n\r\f\v\0") === '') {
            throw new SupertextException(SupertextException::INCOMPLETE_RESPONSE);
        }

        return $body;
    }

    /**
     * Deletes the file at Supertext. Never throws: files expire after 24 hours anyway.
     */
    public function delete(string $fileId): void
    {
        try {
            $this->request('DELETE', self::filePath($fileId));
        } catch (\Throwable) {
            // Nothing the caller could do about it.
        }
    }

    /**
     * Translates a complete HTML document and returns the translated HTML. The file is always deleted.
     *
     * Elements to be matched back afterwards should carry data-st-id attributes (see HtmlDocument).
     */
    public function translateHtml(string $html, string $targetLang, ?string $sourceLang = null, string $politeness = Politeness::DEFAULT): string
    {
        $fileId = $this->submit($html, $targetLang, $sourceLang, $politeness);
        try {
            $this->waitUntilDone($fileId);

            return $this->download($fileId);
        } finally {
            $this->delete($fileId);
        }
    }

    /**
     * Translates keyed texts and returns the translations under the same keys, in the same order.
     *
     * Texts are packed into as few documents as $maxCharacters allows and translated one document after
     * the other. Blank texts come back unchanged without being sent; if nothing is left, no request is made.
     *
     * Note that PHP turns numeric string keys ("1") into integers; the result uses the same keys as the input.
     *
     * @template K of array-key
     *
     * @param array<K, Segment|string> $segments key → Segment (a string is plain text)
     *
     * @return array<K, string>
     *
     * @throws SupertextException incomplete_response (detail "missing: key1, key2") when segments are missing
     */
    public function translateTexts(
        array $segments,
        string $targetLang,
        ?string $sourceLang = null,
        string $politeness = Politeness::DEFAULT,
        int $maxCharacters = Supertext::DEFAULT_MAX_CHARACTERS,
    ): array {
        $result = [];
        $toSend = [];
        foreach ($segments as $key => $segment) {
            $segment = $segment instanceof Segment ? $segment : Segment::text($segment);
            $result[$key] = $segment->text;
            if (!$segment->isBlank()) {
                $toSend[$key] = $segment;
            }
        }

        foreach (self::batches($toSend, $maxCharacters) as $batch) {
            $keys = array_keys($batch);
            $list = array_values($batch);
            $translated = $this->translateHtml(HtmlDocument::build($list), $targetLang, $sourceLang, $politeness);
            $parsed = HtmlDocument::parse($translated, $list);

            $missing = [];
            foreach ($parsed as $index => $text) {
                if ($text === null) {
                    $missing[] = (string) $keys[$index];
                } else {
                    $result[$keys[$index]] = $text;
                }
            }
            if ($missing !== []) {
                throw new SupertextException(SupertextException::INCOMPLETE_RESPONSE, null, 'missing: ' . implode(', ', $missing));
            }
        }

        return $result;
    }

    /**
     * Splits segments into documents of at most $maxCharacters packed characters (a longer segment goes alone).
     *
     * @template K of array-key
     *
     * @param array<K, Segment> $segments non-blank segments, in order
     *
     * @return list<array<K, Segment>>
     */
    public static function batches(array $segments, int $maxCharacters = Supertext::DEFAULT_MAX_CHARACTERS): array
    {
        $batches = [];
        $current = [];
        $length = 0;
        foreach ($segments as $key => $segment) {
            $size = Utf8::length(HtmlDocument::pack($segment));
            if ($current !== [] && $length + $size > $maxCharacters) {
                $batches[] = $current;
                $current = [];
                $length = 0;
            }
            $current[$key] = $segment;
            $length += $size;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    private static function filePath(string $fileId): string
    {
        return 'translate/ai/file/' . rawurlencode($fileId);
    }

    /**
     * Sends a request, retrying HTTP 429, and returns the 2xx answer.
     *
     * @throws SupertextException
     */
    private function request(string $method, string $path, ?string $body = null, ?string $contentType = null): HttpResponse
    {
        // Throws missing_api_key before anything is sent.
        $authorization = Supertext::authHeader($this->apiKey);
        $headers = [
            'Authorization' => $authorization,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $request = new HttpRequest($method, $this->baseUrl . $path, $headers, $body, $this->requestTimeout);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($request);
            } catch (\Throwable $e) {
                $message = trim($e->getMessage());
                $detail = self::redact($message, $authorization);
                // Keep the original for debugging, unless its message carries the key.
                $previous = $detail === $message ? $e : null;
                throw new SupertextException(SupertextException::TRANSPORT_ERROR, null, $detail !== '' ? $detail : null, previous: $previous);
            }
            if ($response->status !== 429 || $attempt >= $this->maxRetries) {
                break;
            }
            ($this->sleep)(Supertext::retryDelayMs($attempt, $response->header('Retry-After')) / 1000);
        }

        if ($response->status >= 200 && $response->status < 300) {
            return $response;
        }

        $error = self::errorFor($response);
        $detail = $error->detail === null ? null : self::redact($error->detail, $authorization);
        if ($detail !== $error->detail) {
            // Never pass the key on, not even if a proxy or server echoes it back.
            $error = new SupertextException($error->errorCode, $error->status, $detail, $error->apiErrorCode, $error->sourceLang, $error->targetLang);
        }

        throw $error;
    }

    /** Removes the key (taken from the Authorization header value) from a text. */
    private static function redact(string $text, string $authorization): string
    {
        $key = substr($authorization, \strlen('Supertext-Auth-Key '));

        return $key === '' ? $text : str_replace($key, '[API key]', $text);
    }

    /** Maps a non-2xx answer to a SupertextException (see spec/errors.json). */
    public static function errorFor(HttpResponse $response): SupertextException
    {
        $status = $response->status;
        $data = self::json($response);
        $apiErrorCode = isset($data['error_code']) && \is_string($data['error_code']) ? $data['error_code'] : null;

        $detail = trim((string) preg_replace('/<[^>]*>/', '', $response->body), " \t\n\r\f");
        $detail = $detail === '' ? null : Utf8::truncate($detail, 200);

        $code = match (true) {
            // Checked on the raw body: the API has answered with JSON and with plain text.
            $status === 400 && str_contains($response->body, 'INVALID_LANGUAGE_PAIR') => SupertextException::INVALID_LANGUAGE_PAIR,
            $status === 401, $status === 403 => SupertextException::AUTHENTICATION_FAILURE,
            $status === 404 => SupertextException::NOT_FOUND,
            $status === 413 => SupertextException::PAYLOAD_TOO_LARGE,
            $status === 429 => SupertextException::TOO_MANY_REQUESTS,
            $status >= 500 && $status <= 599 => SupertextException::SERVICE_UNAVAILABLE,
            default => SupertextException::UNEXPECTED_STATUS,
        };

        $sourceLang = $targetLang = null;
        if ($code === SupertextException::INVALID_LANGUAGE_PAIR) {
            $sourceLang = isset($data['source_lang']) && \is_string($data['source_lang']) ? $data['source_lang'] : null;
            $targetLang = isset($data['target_lang']) && \is_string($data['target_lang']) ? $data['target_lang'] : null;
        }

        return new SupertextException($code, $status, $detail, $apiErrorCode, $sourceLang, $targetLang);
    }

    /** @return array<mixed> the JSON object in the body, or [] (also for JSON arrays and scalars) */
    private static function json(HttpResponse $response): array
    {
        $data = json_decode($response->body);

        return $data instanceof \stdClass ? (array) $data : [];
    }
}
