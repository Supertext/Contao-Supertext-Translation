<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\ContaoTranslation\SupertextTranslationBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends TestCase
{
    public function testLanguageKeysAndFieldNamesAreKeptAsWritten(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new SupertextTranslationBundle();
        $extension = $bundle->getContainerExtension();
        $container->registerExtension($extension);

        $extension->load([[
            'language_map' => ['de-CH' => 'de-CH', 'pt_BR' => 'pt-BR'],
            'politeness' => ['de-CH' => 'more'],
            'fields' => ['tl_content' => ['myCustomField' => 'html', 'caption' => false]],
        ]], $container);

        $this->assertSame(['de-CH' => 'de-CH', 'pt_BR' => 'pt-BR'], $container->getParameter('supertext_translation.language_map'));
        $this->assertSame(['de-CH' => 'more'], $container->getParameter('supertext_translation.politeness'));
        $this->assertSame(['tl_content' => ['myCustomField' => 'html', 'caption' => false]], $container->getParameter('supertext_translation.fields'));
        $this->assertSame('live', $container->getParameter('supertext_translation.environment'));
    }
}
