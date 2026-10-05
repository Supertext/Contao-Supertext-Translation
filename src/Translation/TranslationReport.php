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
     *     pages: list<array{id: int, title: string, created: bool}>,
     *     articles: int,
     *     elements: int,
     *     hidden: int,
     *     missing: list<string>,
     *     warnings: list<string>,
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
            'pages' => [],
            'articles' => 0,
            'elements' => 0,
            'hidden' => 0,
            'missing' => [],
            'warnings' => [],
        ];
    }

    public function fail(int $rootId, string $message, string|null $code = null): void
    {
        $this->targets[$rootId]['ok'] = false;
        $this->targets[$rootId]['error'] = $message;
        $this->targets[$rootId]['errorCode'] = $code;
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
