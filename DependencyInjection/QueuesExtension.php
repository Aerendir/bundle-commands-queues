<?php

namespace SerendipityHQ\Bundle\QueuesBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Config\FileLocator;

/**
 * {@inheritdoc}
 */
class QueuesExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        // Set parameters in the container
        $container->setParameter('queues.db_driver', $config['db_driver']);
        $container->setParameter(sprintf('queues.backend_%s', $config['db_driver']), true);
        $container->setParameter('queues.model_manager_name', $config['model_manager_name']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        // load db_driver container configuration
        $loader->load(sprintf('%s.yml', $config['db_driver']));
    }
}
