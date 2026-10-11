<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

use Supertext\ContaoTranslation\Sdk\Http\HttpRequest;
use Supertext\ContaoTranslation\Sdk\Http\HttpResponse;
use Supertext\ContaoTranslation\Sdk\Http\HttpTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends the Supertext SDK's requests through Symfony's HTTP client, so the bundle uses
 * the framework's HTTP settings (proxy, CA bundle) and tests can use MockHttpClient.
 *
 * Every HTTP answer, 4xx and 5xx included, goes back as an HttpResponse; only a missing
 * answer (DNS, TLS, timeout) throws, which the SDK reports as `transport_error`.
 */
final class SymfonyHttpTransport implements HttpTransport
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $headers = [];

        foreach ($request->headers as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        $response = $this->httpClient->request($request->method, $request->url, [
            'headers' => $headers,
            'body' => $request->body ?? '',
            'timeout' => $request->timeout,
            'max_duration' => $request->timeout,
        ]);

        // getStatusCode() waits for the answer; transport errors surface here or below.
        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        $responseHeaders = [];

        foreach ($response->getHeaders(false) as $name => $values) {
            $responseHeaders[$name] = implode(', ', $values);
        }

        return new HttpResponse($status, $body, $responseHeaders);
    }
}
