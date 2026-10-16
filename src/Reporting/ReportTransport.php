<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Reporting;

use Supertext\ContaoTranslation\PluginHealth\Http\HttpRequest;
use Supertext\ContaoTranslation\PluginHealth\Http\HttpResponse;
use Supertext\ContaoTranslation\PluginHealth\Http\HttpTransport;
use Supertext\ContaoTranslation\PluginHealth\Http\TransportException;
use Supertext\ContaoTranslation\Sdk\Http\HttpRequest as SdkHttpRequest;
use Supertext\ContaoTranslation\Sdk\Http\TransportException as SdkTransportException;
use Supertext\ContaoTranslation\Supertext\SymfonyHttpTransport;

/**
 * Sends Supertext Plugin Health's version reports through the same Symfony HttpClient
 * transport as the SDK (proxy and TLS settings apply, no ext-curl needed). Plugin Health
 * has its own HTTP types; this only converts them.
 */
final class ReportTransport implements HttpTransport
{
    public function __construct(private readonly SymfonyHttpTransport $transport)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->transport->send(new SdkHttpRequest($request->method, $request->url, $request->headers, $request->body, $request->timeout));
        } catch (SdkTransportException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new HttpResponse($response->status, $response->body, $response->headers);
    }
}
