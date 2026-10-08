<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Translation;

/**
 * Outcome of one translation run, per target website root.
 */
final class TranslationReport
{
    /**
     * @var array<int, array{
     *     root: array{id: int, title: string, language: string},
     *     ok: bool,
     *     error: string|null,
     *     errorCode: string|null,
     *     errorParams: list<string|int>,
     *     errorDetail: string,
     *     pages: list<array{id: int, title: string, created: bool}>,
     *     articles: int,
     *     elements: int,
     *     hidden: int,
     *     missing: list<string>,
     *     warnings: list<array{0: string, 1: list<string>}>,
     * }>
     */
    public array $targets = [];

    public function start(array $root): void
    {
        $this->targets[(int) $root['id']] ??= [
            'root' => ['id' => (int) $root['id'], 'title' => (string) $root['title'], 'language' => (string) $root['language']],
            'ok' => true,
            'error' => null,
            'errorCode' => null,
            'errorParams' => [],
            'errorDetail' => '',
            'pages' => [],
            'articles' => 0,
            'elements' => 0,
            'hidden' => 0,
            'missing' => [],
            'warnings' => [],
        ];
    }

    /**
     * `$message` is English (logs); the back end shows `MSC.supertext.error_<code>`
     * with `$params`, followed by the untranslated `$detail`.
     *
     * @param list<string|int> $params
     */
    public function fail(int $rootId, string $message, string|null $code = null, array $params = [], string $detail = ''): void
    {
        $this->targets[$rootId]['ok'] = false;
        $this->targets[$rootId]['error'] = $message;
        $this->targets[$rootId]['errorCode'] = $code;
        $this->targets[$rootId]['errorParams'] = $params;
        $this->targets[$rootId]['errorDetail'] = $detail;
    }

    /** @param list<string> $params */
    public function warn(int $rootId, string $key, array $params = []): void
    {
        $this->targets[$rootId]['warnings'][] = [$key, $params];
    }

    public function hasErrors(): bool
    {
        foreach ($this->targets as $t) {
            if (!$t['ok']) {
                return true;
            }
        }

        return false;
    }
}
