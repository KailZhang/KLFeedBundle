<?php

namespace KL\FeedBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('kl_feed');

        $rootNode
            ->children()
                ->booleanNode('global_subscribe')->defaultFalse()->end()
                ->scalarNode('usermanager_service')->isRequired()->cannotBeEmpty()->end()
                ->variableNode('types')
                    ->defaultValue(array())->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
