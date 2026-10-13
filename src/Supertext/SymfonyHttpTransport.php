<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Supertext;

use Supertext\Sdk\Http\HttpRequest;
use Supertext\Sdk\Http\HttpResponse;
use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\Http\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends the Supertext SDK's requests through Contao's Symfony HttpClient, so its proxy
 * and TLS settings apply (and ext-curl isn't needed). Every HTTP answer, 4xx and 5xx
 * included, goes back to the SDK; only a missing answer is an exception.
 */
final class SymfonyHttpTransport implements HttpTransport
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->http->request($request->method, $request->url, [
                'headers' => $request->headers,
                'body' => $request->body ?? '',
                'timeout' => $request->timeout,
                'max_duration' => $request->timeout,
            ]);
            $headers = array_map(static fn (array $values): string => implode(', ', $values), $response->getHeaders(false));

            return new HttpResponse($response->getStatusCode(), $response->getContent(false), $headers);
        } catch (TransportExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }
}
