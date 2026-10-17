<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\EventListener;

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Supertext\ContaoTranslation\Translation\Languages;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The *Supertext language* and *Formal or informal* selects in a website root's settings
 * (tl_page, type root; fields in contao/dca/tl_page.php).
 *
 * - Options: Supertext's targets (the SDK's targetOptions()) as "Name (code)", names in the back-end language.
 * - Preselected: the saved code, else the SDK's suggestTarget() for the root's language, else "— choose —".
 *   Only a choice that differs from the suggestion is stored, so the default follows the root's language.
 * - Saving accepts only Supertext's codes.
 * - Formal or informal is shown only when the root's Supertext language offers it.
 * - A `language_map` / `politeness` entry in the bundle configuration overrides the field; its help text says so.
 */
final class RootLanguageListener
{
    public function __construct(
        private readonly Languages $languages,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsCallback(table: 'tl_page', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        $GLOBALS['TL_DCA']['tl_page']['fields']['supertext_language']['eval']['blankOptionLabel'] = $this->trans('MSC.supertext.choose');

        if (null === $dc || !$dc->id) {
            return;
        }

        $record = $dc->getCurrentRecord();

        if (!\is_array($record) || 'root' !== ($record['type'] ?? null)) {
            return;
        }

        $language = (string) ($record['language'] ?? '');
        $chosen = (string) ($record['supertext_language'] ?? '');

        if (null !== $configured = $this->languages->configured($language)) {
            $this->explain('supertext_language', $this->trans('MSC.supertext.configured', [$configured]));
        }

        if (null !== $configured = $this->languages->configuredPoliteness($language)) {
            $this->explain('supertext_politeness', $this->trans('MSC.supertext.politenessConfigured', [$configured]));
        }

        // Formal or informal only where the root's Supertext language offers it.
        $target = $this->languages->targetOrNull($language, $chosen);

        if (null === $target || !Languages::supportsPoliteness($target)) {
            foreach (['root', 'rootfallback'] as $palette) {
                if (isset($GLOBALS['TL_DCA']['tl_page']['palettes'][$palette])) {
                    PaletteManipulator::create()
                        ->removeField('supertext_politeness')
                        ->applyToPalette($palette, 'tl_page')
                    ;
                }
            }
        }
    }

    /** @return array<string, string> */
    #[AsCallback(table: 'tl_page', target: 'fields.supertext_language.options')]
    public function options(): array
    {
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en';

        return Languages::targetLabels(str_replace('-', '_', $locale));
    }

    #[AsCallback(table: 'tl_page', target: 'fields.supertext_language.load')]
    public function load(mixed $value, DataContainer $dc): string
    {
        $value = \is_string($value) ? $value : '';

        if ('' !== $value) {
            return $value;
        }

        $record = $dc->getCurrentRecord();

        return Languages::suggest((string) ($record['language'] ?? ''));
    }

    #[AsCallback(table: 'tl_page', target: 'fields.supertext_language.save')]
    public function save(mixed $value, DataContainer $dc): string
    {
        $value = trim(\is_string($value) ? $value : '');

        if ('' === $value) {
            return '';
        }

        if (!\in_array($value, Languages::targetCodes(), true)) {
            throw new \RuntimeException($this->trans('MSC.supertext.invalidChoice'));
        }

        // The language as submitted in the same form, else as stored.
        $language = $this->requestStack->getCurrentRequest()?->request->get('language');

        if (!\is_string($language)) {
            $language = (string) ($dc->getCurrentRecord()['language'] ?? '');
        }

        // Store only a real choice: the suggestion stays '' and follows a changed root language.
        return Languages::suggest($language) === $value ? '' : $value;
    }

    private function explain(string $field, string $note): void
    {
        $label = $GLOBALS['TL_DCA']['tl_page']['fields'][$field]['label'] ?? $GLOBALS['TL_LANG']['tl_page'][$field] ?? [$field, ''];
        $GLOBALS['TL_DCA']['tl_page']['fields'][$field]['label'] = [(string) ($label[0] ?? $field), $note];
    }

    /** @param list<string> $params */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'contao_default');
    }
}
