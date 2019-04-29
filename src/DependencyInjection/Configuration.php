<?php

declare(strict_types=1);

/*
 * This file is part of the SHQCommandsQueuesBundle.
 *
 * Copyright Adamo Aerendir Crespi 2017.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author    Adamo Aerendir Crespi <hello@aerendir.me>
 * @copyright Copyright (C) 2017 Aerendir. All rights reserved.
 * @license   MIT License.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection;

use Safe\Exceptions\ArrayException;
use Safe\Exceptions\StringsException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * @author Audrius Karabanovas <audrius@karabanovas.net>
 * @author Adamo Aerendir Crespi <hello@aerendir.me>
 *
 * {@inheritdoc}
 */
class Configuration implements ConfigurationInterface
{
    /** @var int The maximum amount of days after which the finished jobs are deleted */
    private const MAX_RETENTION_DAYS = 0;

    /** @var array $foundQueues The queues found processing the Daemons */
    private $foundQueues = [];

    /**
     * The list of supported ORM drivers.
     *
     * @return array
     */
    public static function getSupportedDrivers(): array
    {
        return ['orm'];
    }

    /**
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('shq_commands_queues');
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('db_driver')
                    ->info('The Doctrine driver to use. Currently supports only "orm". Don not use this parameter for the moment.')
                    ->validate()
                        ->ifNotInArray(self::getSupportedDrivers())
                        ->thenInvalid('The driver %s is not supported. Please choose one of ' . json_encode(self::getSupportedDrivers()))
                    ->end()
                    ->cannotBeOverwritten()
                    ->defaultValue('orm')
                    ->cannotBeEmpty()
                ->end()
                // In seconds (1 hour)
                ->integerNode('alive_daemons_check_interval')->defaultValue(3600)->end()
                // In seconds
                ->integerNode('idle_time')->defaultValue(10)->end()
                ->integerNode('max_concurrent_jobs')->defaultValue(1)->end()
                // In seconds (3 minutes)
                ->integerNode('max_runtime')->defaultValue(90)->end()
                // In seconds (5 minutes)
                ->integerNode('profiling_info_interval')->defaultValue(350)->end()
                ->booleanNode('print_profiling_info')->defaultTrue()->end()
                // The maximum amount of days after which the finished jobs are deleted
                ->scalarNode('max_retention_days')->defaultValue(self::MAX_RETENTION_DAYS)->end()
                ->scalarNode('retry_stale_jobs')->defaultTrue()->end()
                // In seconds
                ->integerNode('running_jobs_check_interval')->defaultValue(10)->end()
                ->integerNode('managed_entities_treshold')->defaultValue(100)->end()
                ->arrayNode('daemons')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->integerNode('idle_time')->defaultNull()->end()
                            ->integerNode('max_runtime')->defaultNull()->end()
                            ->integerNode('profiling_info_interval')->defaultNull()->end()
                            ->integerNode('print_profiling_info')->defaultNull()->end()
                            ->scalarNode('retry_stale_jobs')->defaultNull()->end()
                            ->integerNode('running_jobs_check_interval')->defaultNull()->end()
                            ->integerNode('managed_entities_treshold')->defaultValue(100)->end()
                            // The maximum amount of days after which the finished jobs are deleted
                            ->scalarNode('max_retention_days')->defaultValue(self::MAX_RETENTION_DAYS)->end()
                            ->arrayNode('queues')
                                ->prototype('scalar')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('queues')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->integerNode('max_concurrent_jobs')->defaultNull()->end()
                            // The maximum amount of days after which the finished jobs are deleted
                            ->scalarNode('max_retention_days')->defaultValue(self::MAX_RETENTION_DAYS)->end()
                            ->scalarNode('retry_stale_jobs')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
        ->end()
            ->validate()
            // Deeply validate the full config tree
            ->ifTrue(function ($tree) {
                return $this->validateConfiguration($tree);
            })
            // Re-elaborate the tree removing unuseful values and preparing useful ones
            ->then(function ($tree) {
                return $this->prepareConfiguration($tree);
            })
            ->end();

        return $treeBuilder;
    }

    /**
     * @param array $tree
     *
     * @throws StringsException
     *
     * @return bool
     */
    private function validateConfiguration(array $tree): bool
    {
        foreach ($tree['daemons'] as $daemon => $config) {
            // A Daemon MUST HAVE at least one queue assigned
            if (empty($config['queues'])) {
                throw new InvalidConfigurationException(\Safe\sprintf(
                    'The "%s" daemon MUST specify at least one queue to process.', $daemon
                ));
            }

            // Check the queue is not already assigned
            foreach ($config['queues'] as $queue) {
                if (array_key_exists($queue, $this->foundQueues)) {
                    throw new InvalidConfigurationException(\Safe\sprintf(
                        'Queue "%s" already assigned to daemon "%s". You cannot assign this queue also to daemon "%s".',
                        $queue, $this->foundQueues[$queue], $daemon
                    ));
                }

                $this->foundQueues[$queue] = $daemon;
            }
        }

        return true;
    }

    /**
     * Prepares the definitive configuration.
     *
     * @param array $tree
     *
     * @throws ArrayException
     *
     * @return array
     */
    private function prepareConfiguration(array $tree): array
    {
        // Create the main configuration array to return
        $returnConfig = [
            'db_driver'          => $tree['db_driver'],
            'daemons'            => $tree['daemons'],
            'queues'             => $tree['queues'],
        ];

        if (0 === count($returnConfig['daemons'])) {
            $returnConfig['daemons'][Daemon::DEFAULT_DAEMON_NAME] = [];
        }

        // Configure each daemon
        foreach ($returnConfig['daemons'] as $daemon => $config) {
            $returnConfig['daemons'][$daemon] = $this->configureDaemon($config, $tree);
        }

        // Add all the found queues to the queues array
        $this->foundQueues = array_keys($this->foundQueues);
        foreach ($this->foundQueues as $queue) {
            if (false === array_key_exists($queue, $returnConfig['queues'])) {
                $returnConfig['queues'][$queue] = [];
            }
        }

        // Sort queues alphabetically
        \Safe\ksort($returnConfig['queues']);

        // Now configure the queues
        foreach ($returnConfig['queues'] as $queue => $config) {
            $returnConfig['queues'][$queue] = $this->configureQueue($config, $tree);
        }

        return $returnConfig;
    }

    /**
     * @param array $config
     * @param array $tree
     *
     * @return array
     */
    private function configureDaemon(array $config, array $tree): array
    {
        if (false === isset($config['queues'])) {
            $config['queues'][]                            = Daemon::DEFAULT_QUEUE_NAME;
            $this->foundQueues[Daemon::DEFAULT_QUEUE_NAME] = Daemon::DEFAULT_QUEUE_NAME;
        }

        return [
            // Daemon specific configurations
            'alive_daemons_check_interval' => $config['alive_daemons_check_interval'] ?? $tree['alive_daemons_check_interval'],
            'idle_time'                    => $config['idle_time'] ?? $tree['idle_time'],
            'max_runtime'                  => $config['max_runtime'] ?? $tree['max_runtime'],
            'profiling_info_interval'      => $config['profiling_info_interval'] ?? $tree['profiling_info_interval'],
            'print_profiling_info'         => $config['print_profiling_info'] ?? $tree['print_profiling_info'],
            // Queues specific configurations
            'retry_stale_jobs'            => $config['retry_stale_jobs'] ?? $tree['retry_stale_jobs'],
            'running_jobs_check_interval' => $config['running_jobs_check_interval'] ?? $tree['running_jobs_check_interval'],
            'managed_entities_treshold'   => $config['managed_entities_treshold'] ?? $tree['managed_entities_treshold'],
            'max_retention_days'          => $config['max_retention_days'] ?? $tree['max_retention_days'],
            'queues'                      => $config['queues'],
        ];
    }

    /**
     * @param array $config
     * @param array $tree
     *
     * @return array
     */
    private function configureQueue(array $config, array $tree): array
    {
        return [
            'max_concurrent_jobs'         => $config['max_concurrent_jobs'] ?? $tree['max_concurrent_jobs'],
            'max_retention_days'          => $config['max_retention_days'] ?? $tree['max_retention_days'],
            'retry_stale_jobs'            => $config['retry_stale_jobs'] ?? $tree['retry_stale_jobs'],
            'running_jobs_check_interval' => $config['running_jobs_check_interval'] ?? $tree['running_jobs_check_interval'],
        ];
    }
}
