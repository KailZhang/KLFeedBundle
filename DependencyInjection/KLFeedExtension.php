<?php

namespace KL\FeedBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class KLFeedExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');
        
        $container->setParameter('kl_feed.usermanager_service', $config['usermanager_service']);
        $container->setParameter('kl_feed.global_subscribe', $config['global_subscribe']);
        $feed_types = $config['types'];
        if ($feed_types === null) {
            // @todo feed is actually disabled
        }
        
        $container->setParameter('kl_feed.types', $feed_types);
    }
}
