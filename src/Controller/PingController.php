<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Controller;

use Supertext\ContaoTranslation\Reporting\Reporting;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public ping for the Supertext Commando Center: GET /_supertext/ping?challenge=<c> answers
 * {"ok":true,"plugin":"Contao-Supertext-Translation","challenge":"<c>"} (Supertext Plugin Health's answer,
 * status and headers as given), 400 for an invalid challenge, 404 while version reports
 * are off. No `_scope`: Contao's firewalls, session and page cache don't apply; the answer
 * says Cache-Control: no-store.
 */
#[Route(Reporting::PING_PATH, name: Reporting::PING_ROUTE, methods: ['GET'])]
final class PingController
{
    public function __construct(private readonly Reporting $reporting)
    {
    }

    public function __invoke(Request $request): Response
    {
        $ping = $this->reporting->ping($request->query->get('challenge'));

        return new Response($ping->body, $ping->status, $ping->headers);
    }
}
