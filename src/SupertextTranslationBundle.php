<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation;

use Supertext\ContaoTranslation\Html\FieldCodec;
use Supertext\ContaoTranslation\Supertext\SupertextClient;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Configuration (config/config.yaml of the Contao installation):
 *
 * API key: create a Supertext account at https://www.supertext.com/person/en/account/signin
 * and generate the key at https://www.supertext.com/en/integrations/api
 * (supertext.com → Integrations → API, requires the Admin role).
 *
 *     supertext_translation:
 *         api_key: '%env(SUPERTEXT_API_KEY)%'   # default
 *         environment: live                     # live | staging | testing (env var OK)
 *         api_url: ''                           # overrides environment (env var OK)
 *         language_map: { de-CH: de-CH }
 *         politeness: { de-CH: more }
 *         poll_interval: 2                      # seconds
 *         timeout: 180                          # seconds per page
 *         fields:
 *             tl_content: { myCustomField: html, caption: false }
 */
final class SupertextTranslationBundle extends AbstractBundle
{
    protected string $extensionAlias = 'supertext_translation';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->defaultValue('%env(default::SUPERTEXT_API_KEY)%')->end()
                ->scalarNode('environment')->info(implode(' | ', array_keys(SupertextClient::ENVIRONMENTS)))->defaultValue('live')->end()
                ->scalarNode('api_url')->defaultValue('')->end()
                // normalizeKeys(false): keep "de-CH" (Symfony would turn dashes into underscores)
                ->arrayNode('language_map')->normalizeKeys(false)->useAttributeAsKey('language')->scalarPrototype()->end()->end()
                ->arrayNode('politeness')->normalizeKeys(false)->useAttributeAsKey('language')->enumPrototype()->values(['default', 'less', 'more'])->end()->end()
                ->integerNode('poll_interval')->min(1)->defaultValue(2)->end()
                ->integerNode('timeout')->min(10)->defaultValue(180)->end()
                ->arrayNode('fields')
                    ->normalizeKeys(false)
                    ->info('Per table: field => '.implode('|', FieldCodec::TYPES).', or false to skip a default field')
                    ->useAttributeAsKey('table')
                    ->arrayPrototype()
                        ->normalizeKeys(false)
                        ->useAttributeAsKey('field')
                        ->variablePrototype()
                            ->validate()
                                ->ifTrue(static fn ($v) => false !== $v && null !== $v && !\in_array($v, FieldCodec::TYPES, true))
                                ->thenInvalid('Field type must be one of '.implode(', ', FieldCodec::TYPES).' or false.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $container->parameters()
            ->set('supertext_translation.api_key', $config['api_key'])
            ->set('supertext_translation.api_url', (string) $config['api_url'])
            ->set('supertext_translation.environment', (string) $config['environment'])
            ->set('supertext_translation.language_map', $config['language_map'])
            ->set('supertext_translation.politeness', $config['politeness'])
            ->set('supertext_translation.poll_interval_ms', $config['poll_interval'] * 1000)
            ->set('supertext_translation.timeout_ms', $config['timeout'] * 1000)
            ->set('supertext_translation.fields', $config['fields'])
        ;
    }
}
