<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests;

use Supertext\ContaoTranslation\Sdk\V1\Languages;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * In-memory stand-in for the Supertext AI file API. "Translates" by prefixing the
 * first text of every segment with `[<target_lang>] `. Records every request.
 */
final class FakeSupertext
{
    /** @var list<array{method: string, url: string, body: string}> */
    public array $calls = [];

    /** @var array<string, array{html: string, target: string, source: string, politeness: string, type: string, polls: int}> */
    public array $files = [];

    private int $counter = 0;

    /** Answer this many requests with HTTP 429 (rate limit) first. */
    public int $rateLimited = 0;

    /** @var list<array<string, mixed>> pending corrections the review service answers (raw items, as the API sends them) */
    public array $corrections = [];

    /** @var list<array{id: string, version: string}> acknowledged corrections */
    public array $acknowledged = [];

    /** @var list<array{html: string, target: string, source: string, politeness: string, type: string}> every uploaded file */
    public array $submitted = [];

    /** @param list<string> $statuses status sequence returned while polling */
    public function __construct(
        private readonly array $statuses = ['done'],
        private readonly int|null $failStatus = null,
        private readonly \Closure|null $transform = null,
    ) {
    }

    /** @return list<array{method: string, url: string, body: string}> */
    public function calls(\Closure $filter): array
    {
        return array_values(array_filter($this->calls, $filter));
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this->respond(...), 'https://api.test/v1/');
    }

    /** @return array<string, array{headers: array<string, string>, body: string}> */
    public static function parseMultipart(string $body): array
    {
        $boundary = strtok($body, "\r\n");
        $parts = [];

        foreach (explode($boundary, $body) as $chunk) {
            if (!str_contains($chunk, "\r\n\r\n")) {
                continue;
            }

            [$head, $content] = explode("\r\n\r\n", ltrim($chunk, "\r\n"), 2);
            $headers = [];

            foreach (explode("\r\n", $head) as $line) {
                [$k, $v] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
                $headers[strtolower($k)] = $v;
            }

            preg_match('/name="([^"]+)"/', $headers['content-disposition'] ?? '', $n);
            $parts[$n[1] ?? ''] = ['headers' => $headers, 'body' => substr($content, 0, -2)];
        }

        return $parts;
    }

    private static function readBody(mixed $body): string
    {
        if (\is_string($body)) {
            return $body;
        }

        if ($body instanceof \Closure) {
            $out = '';

            while ('' !== ($chunk = $body(16384))) {
                $out .= $chunk;
            }

            return $out;
        }

        return \is_iterable($body) ? implode('', iterator_to_array($body, false)) : '';
    }

    private function respond(string $method, string $url, array $options): MockResponse
    {
        $body = self::readBody($options['body'] ?? '');

        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        $headers = implode("\n", $options['headers'] ?? []);

        if (!str_contains($headers, 'Authorization: Supertext-Auth-Key test-key')) {
            return new MockResponse('bad key', ['http_code' => 401]);
        }

        if ($this->failStatus) {
            return new MockResponse('nope', ['http_code' => $this->failStatus]);
        }

        if ($this->rateLimited > 0) {
            --$this->rateLimited;

            return new MockResponse('{"error_code":"RATE_LIMIT_EXCEEDED"}', ['http_code' => 429]);
        }

        if ('GET' === $method && str_ends_with($url, '/features')) {
            return new MockResponse('{}');
        }

        if ('POST' === $method && str_ends_with($url, 'translate/ai/file')) {
            $parts = self::parseMultipart($body);
            $target = $parts['target_lang']['body'] ?? '';
            $source = $parts['source_lang']['body'] ?? '';
            // Like the real API: only the exact codes of GET features (bare `de` or `es-ES` are 404).
            if (!\in_array($target, array_column(Languages::TARGETS, 'code'), true) || ('' !== $source && !\in_array($source, array_column(Languages::SOURCES, 'code'), true))) {
                return new MockResponse('{"error_code":"INVALID_LANGUAGE_PAIR"}', ['http_code' => 404]);
            }
            // Formal/informal only where offered (not en-US, rm, …).
            if (\in_array($parts['politeness']['body'] ?? '', ['more', 'less'], true) && false === Languages::supportsPoliteness($target)) {
                return new MockResponse('{"error_code":"FEATURE_ERROR","feature":"politeness"}', ['http_code' => 400]);
            }
            $id = 'f'.(++$this->counter);
            $this->files[$id] = [
                'html' => $parts['file']['body'] ?? '',
                'target' => $parts['target_lang']['body'] ?? '',
                'source' => $parts['source_lang']['body'] ?? '',
                'politeness' => $parts['politeness']['body'] ?? '',
                'type' => $parts['file']['headers']['content-type'] ?? '',
                'polls' => 0,
            ];
            $this->submitted[] = $this->files[$id];

            return new MockResponse(json_encode(['file_id' => $id]), ['response_headers' => ['content-type' => 'application/json']]);
        }

        // The review service's corrections (Supertext Localization Review).
        if ('GET' === $method && preg_match('#/review/corrections\?(.*)$#', $url, $q)) {
            parse_str($q[1], $query);
            $items = array_values(array_filter($this->corrections, static fn (array $c) => !isset($query['target_lang']) || $c['target_lang'] === $query['target_lang']));

            return new MockResponse((string) json_encode(['corrections' => $items, 'next_after' => null]));
        }

        if ('POST' === $method && str_ends_with($url, '/review/corrections/acknowledge')) {
            $items = json_decode($body, true)['corrections'] ?? [];
            $this->acknowledged = [...$this->acknowledged, ...$items];

            return new MockResponse((string) json_encode(['acknowledged' => \count($items)]));
        }

        if (!preg_match('#translate/ai/file/([^/]+)(/status|/translation)?$#', $url, $m) || !isset($this->files[$m[1]])) {
            return new MockResponse('not found', ['http_code' => 404]);
        }

        $file = &$this->files[$m[1]];

        if ('/status' === ($m[2] ?? '')) {
            $status = $this->statuses[min($file['polls']++, \count($this->statuses) - 1)];

            return new MockResponse(json_encode(['status' => $status]));
        }

        if ('/translation' === ($m[2] ?? '')) {
            $html = $this->transform
                ? ($this->transform)($file['html'], $file['target'])
                // Prefix the first text inside each segment (after any opening tags).
                : preg_replace('/(<div data-st-id="[^"]*">(?:<[a-z][^>]*>)*)/', '$1['.$file['target'].'] ', $file['html']);

            return new MockResponse((string) $html);
        }

        if ('DELETE' === $method) {
            unset($this->files[$m[1]]);

            return new MockResponse('', ['http_code' => 204]);
        }

        return new MockResponse('unexpected', ['http_code' => 400]);
    }
}
