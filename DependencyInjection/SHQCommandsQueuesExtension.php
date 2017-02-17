<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * {@inheritdoc}
 */
class SHQCommandsQueuesExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameters in the container
        $container->setParameter('commands_queues.db_driver', $config['db_driver']);
        $container->setParameter(sprintf('commands_queues.backend_%s', $config['db_driver']), true);
        $container->setParameter('commands_queues.model_manager_name', $config['model_manager_name']);
        $container->setParameter('commands_queues.alive_daemons_check_interval', $config['alive_daemons_check_interval']);
        $container->setParameter('commands_queues.optimization_interval', $config['optimization_interval']);
        $container->setParameter('commands_queues.running_jobs_check_interval', $config['running_jobs_check_interval']);
        $container->setParameter('commands_queues.print_profiling_info_interval', $config['print_profiling_info_interval']);
        $container->setParameter('commands_queues.config', [
            'max_runtime'         => $config['max_runtime'],
            'max_concurrent_jobs' => $config['max_concurrent_jobs'],
            'idle_time'           => $config['idle_time'],
            'worker_name'         => $config['worker_name'],
            'retry_stale_jobs'    => $config['retry_stale_jobs'],
        ]);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        // load db_driver container configuration
        $loader->load(sprintf('%s.yml', $config['db_driver']));
    }
}
