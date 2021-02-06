<?php

declare(strict_types=1);

/*
 * This file is part of the Serendipity HQ Commands Queues Bundle.
 *
 * Copyright (c) Adamo Aerendir Crespi <aerendir@serendipityhq.com>.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection;

use Safe\Exceptions\ArrayException;
use Safe\Exceptions\StringsException;
use function Safe\ksort;
use function Safe\sprintf;
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
final class Configuration implements ConfigurationInterface
{
    /** @var string */
    public const DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY = 'daemon_alive_daemons_check_interval';

    /** @var string */
    public const DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_DESCRIPTION = 'Indicates to the running daemons after how many seconds they have to check if otherr running daemons are still alive (running). Defaults to 3600 seconds.';

    /** @var string */
    public const DAEMON_MANAGED_ENTITIES_TRESHOLD_KEY = 'daemon_managed_entities_treshold';

    /** @var string */
    public const DAEMON_MANAGED_ENTITIES_TRESHOLD_DESCRIPTION = 'Indicates the maximum number of Jobs that a Daemon can keep in the entity manager at any given time.';

    /** @var string */
    public const DAEMON_MAX_RUNTIME_KEY = 'daemon_max_runtime';

    /** @var string */
    public const DAEMON_MAX_RUNTIME_DESCRIPTION = 'Indicates the maximum amount of seconds the daemon will live. Once elapsed, the daemon will die.';

    /** @var string */
    public const DAEMON_PROFILING_INFO_INTERVAL_KEY = 'daemon_profiling_info_interval';

    /** @var string */
    public const DAEMON_PROFILING_INFO_INTERVAL_DESCRIPTION = 'Indicates the amount of seconds between each profiling information collection and printing in the console log.';

    /** @var string */
    public const DAEMON_PRINT_PROFILING_INFO_KEY = 'daemon_print_profiling_info';

    /** @var string */
    public const DAEMON_PRINT_PROFILING_INFO_DESCRIPTION = 'Indicates the amount of seconds between each profiling information collection and printing in the console log.';

    /** @var string */
    public const DAEMON_SLEEP_FOR_KEY = 'daemon_sleep_for';

    /** @var string */
    public const DAEMON_SLEEP_FOR_DESCRIPTION = 'The amount of seconds the Daemon will sleep when runs out of jobs.';

    /** @var string */
    public const QUEUE_MAX_CONCURRENT_JOBS_KEY = 'queue_max_concurrent_jobs';

    /** @var string */
    public const QUEUE_MAX_CONCURRENT_JOBS_DESCRIPTION = 'The number of concurrent jobs to process at the same time in each queue.';

    /** @var string */
    public const QUEUE_MAX_RETENTION_DAYS_KEY = 'queue_max_retention_days';

    /** @var string */
    public const QUEUE_MAX_RETENTION_DAYS_DESCRIPTION = 'The number of days after which a Job that cannot be run anymore will be considered expired and will be removed from the database.';

    /** @var string */
    public const QUEUE_RETRY_STALE_JOBS_KEY = 'queue_retry_stale_jobs';

    /** @var string */
    public const QUEUE_RETRY_STALE_JOBS_DESCRIPTION = 'If true, stale jobs will be retried when the daemon restarts.';

    /** @var string */
    public const QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY = 'queue_running_jobs_check_interval';

    /** @var string */
    public const QUEUE_RUNNING_JOBS_CHECK_INTERVAL_DESCRIPTION = 'The number of seconds after which the running jobs have to be checked.';
    /**
     * @var string
     */
    private const DAEMONS = 'daemons';
    /**
     * @var string
     */
    private const QUEUES = 'queues';

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
                ->integerNode(self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY)
                    ->info(self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_DESCRIPTION)
                    ->defaultValue(3600)
                ->end()
                ->integerNode(self::DAEMON_MANAGED_ENTITIES_TRESHOLD_KEY)
                    ->info(self::DAEMON_MANAGED_ENTITIES_TRESHOLD_DESCRIPTION)
                    ->defaultValue(100)
                ->end()
                ->integerNode(self::QUEUE_MAX_CONCURRENT_JOBS_KEY)
                    ->info(self::QUEUE_MAX_CONCURRENT_JOBS_DESCRIPTION)
                    ->defaultValue(1)
                ->end()
                // In seconds (3 minutes)
                ->integerNode(self::DAEMON_MAX_RUNTIME_KEY)
                    ->info(self::DAEMON_MAX_RUNTIME_DESCRIPTION)
                    ->defaultValue(0)
                ->end()
                // In seconds (5 minutes)
                ->integerNode(self::DAEMON_PROFILING_INFO_INTERVAL_KEY)
                    ->info(self::DAEMON_PROFILING_INFO_INTERVAL_DESCRIPTION)
                    ->defaultValue(300)
                ->end()
                ->booleanNode(self::DAEMON_PRINT_PROFILING_INFO_KEY)
                    ->info(self::DAEMON_PRINT_PROFILING_INFO_DESCRIPTION)
                    ->defaultTrue()
                ->end()
                // In seconds
                ->integerNode(self::DAEMON_SLEEP_FOR_KEY)
                    ->info(self::DAEMON_SLEEP_FOR_DESCRIPTION)
                    ->defaultValue(10)
                ->end()
                ->integerNode(self::QUEUE_MAX_CONCURRENT_JOBS_KEY)
                    ->info(self::QUEUE_MAX_CONCURRENT_JOBS_DESCRIPTION)
                    ->defaultValue(5)
                ->end()
                // The maximum amount of days after which the finished jobs are deleted
                ->integerNode(self::QUEUE_MAX_RETENTION_DAYS_KEY)
                    ->info(self::QUEUE_MAX_RETENTION_DAYS_DESCRIPTION)
                    ->defaultValue(365)
                ->end()
                ->booleanNode(self::QUEUE_RETRY_STALE_JOBS_KEY)
                    ->info(self::QUEUE_RETRY_STALE_JOBS_DESCRIPTION)
                    ->defaultTrue()
                ->end()
                // In seconds
                ->integerNode(self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY)
                    ->info(self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_DESCRIPTION)
                    ->defaultValue(10)
                ->end()
                ->arrayNode('daemons')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            // In seconds (1 hour)
                            ->integerNode(self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY)
                                ->info(self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY)
                                ->info(self::DAEMON_MANAGED_ENTITIES_TRESHOLD_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::DAEMON_MAX_RUNTIME_KEY)
                                ->info(self::DAEMON_MAX_RUNTIME_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::DAEMON_PROFILING_INFO_INTERVAL_KEY)
                                ->info(self::DAEMON_PROFILING_INFO_INTERVAL_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->booleanNode(self::DAEMON_PRINT_PROFILING_INFO_KEY)
                                ->info(self::DAEMON_PRINT_PROFILING_INFO_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::DAEMON_SLEEP_FOR_KEY)
                                ->info(self::DAEMON_SLEEP_FOR_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::QUEUE_MAX_CONCURRENT_JOBS_KEY)
                                ->info(self::QUEUE_MAX_CONCURRENT_JOBS_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::QUEUE_MAX_RETENTION_DAYS_KEY)
                                ->info(self::QUEUE_MAX_RETENTION_DAYS_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->booleanNode(self::QUEUE_RETRY_STALE_JOBS_KEY)
                                ->info(self::QUEUE_RETRY_STALE_JOBS_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY)
                                ->info(self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_DESCRIPTION)
                                ->defaultNull()
                            ->end()
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
                            ->integerNode(self::QUEUE_MAX_CONCURRENT_JOBS_KEY)
                                ->info(self::QUEUE_MAX_CONCURRENT_JOBS_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::QUEUE_MAX_RETENTION_DAYS_KEY)
                                ->info(self::QUEUE_MAX_RETENTION_DAYS_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->booleanNode(self::QUEUE_RETRY_STALE_JOBS_KEY)
                                ->info(self::QUEUE_RETRY_STALE_JOBS_DESCRIPTION)
                                ->defaultNull()
                            ->end()
                            ->integerNode(self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY)
                                ->info(self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_DESCRIPTION)
                                ->defaultNull()
                            ->end()
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
        if (1 > $tree[self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY]) {
            throw new InvalidConfigurationException(sprintf('The global "%s" config param MUST be greater than 0. You set it to "%s".', self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY, $tree[self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY]));
        }

        foreach ($tree[self::DAEMONS] as $daemon => $config) {
            // A Daemon MUST HAVE at least one queue assigned
            if (empty($config[self::QUEUES])) {
                throw new InvalidConfigurationException(sprintf('The "%s" daemon MUST specify at least one queue to process.', $daemon));
            }

            // Check the queue is not already assigned
            $config[self::QUEUES] = \array_unique($config[self::QUEUES]);
            foreach ($config[self::QUEUES] as $queue) {
                if (\array_key_exists($queue, $this->foundQueues)) {
                    throw new InvalidConfigurationException(sprintf('Queue "%s" already assigned to daemon "%s". You cannot assign this queue also to daemon "%s".', $queue, $this->foundQueues[$queue], $daemon));
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
            'db_driver'              => $tree['db_driver'],
            self::DAEMONS            => $tree[self::DAEMONS],
            self::QUEUES             => $tree[self::QUEUES],
        ];

        if (0 === (\is_array($returnConfig[self::DAEMONS]) || $returnConfig[self::DAEMONS] instanceof \Countable ? \count($returnConfig[self::DAEMONS]) : 0)) {
            $returnConfig[self::DAEMONS][Daemon::DEFAULT_DAEMON_NAME] = [];
        }

        // Configure each daemon
        foreach ($returnConfig[self::DAEMONS] as $daemon => $config) {
            $returnConfig[self::DAEMONS][$daemon] = $this->configureDaemon($config, $tree);
        }

        // Add all the found queues to the queues array
        foreach (\array_keys($this->foundQueues) as $queue) {
            if (false === \array_key_exists($queue, $returnConfig[self::QUEUES])) {
                $returnConfig[self::QUEUES][$queue] = [];
            }
        }

        // Sort queues alphabetically
        ksort($returnConfig[self::QUEUES]);

        // Now configure the queues
        foreach ($returnConfig[self::QUEUES] as $queue => $config) {
            $returnConfig[self::QUEUES][$queue] = $this->configureQueue($this->foundQueues[$queue], $config, $tree);
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
        if (false === isset($config[self::QUEUES])) {
            $config[self::QUEUES][]                            = Daemon::DEFAULT_QUEUE_NAME;
            $this->foundQueues[Daemon::DEFAULT_QUEUE_NAME]     = Daemon::DEFAULT_QUEUE_NAME;
        }

        return [
            // Daemon specific configurations
            self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY     => $config[self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY] ?? $tree[self::DAEMON_ALIVE_DAEMONS_CHECK_INTERVAL_KEY],
            self::DAEMON_MANAGED_ENTITIES_TRESHOLD_KEY        => $config[self::DAEMON_MANAGED_ENTITIES_TRESHOLD_KEY] ?? $tree[self::DAEMON_MANAGED_ENTITIES_TRESHOLD_KEY],
            self::DAEMON_MAX_RUNTIME_KEY                      => $config[self::DAEMON_MAX_RUNTIME_KEY] ?? $tree[self::DAEMON_MAX_RUNTIME_KEY],
            self::DAEMON_PROFILING_INFO_INTERVAL_KEY          => $config[self::DAEMON_PROFILING_INFO_INTERVAL_KEY] ?? $tree[self::DAEMON_PROFILING_INFO_INTERVAL_KEY],
            self::DAEMON_PRINT_PROFILING_INFO_KEY             => $config[self::DAEMON_PRINT_PROFILING_INFO_KEY] ?? $tree[self::DAEMON_PRINT_PROFILING_INFO_KEY],
            self::DAEMON_SLEEP_FOR_KEY                        => $config[self::DAEMON_SLEEP_FOR_KEY] ?? $tree[self::DAEMON_SLEEP_FOR_KEY],
            self::QUEUES                                      => $config[self::QUEUES],
        ];
    }

    /**
     * @param string $daemon
     * @param array  $config
     * @param array  $tree
     *
     * @return array
     */
    private function configureQueue(string $daemon, array $config, array $tree): array
    {
        return [
            self::QUEUE_MAX_CONCURRENT_JOBS_KEY         => $config[self::QUEUE_MAX_CONCURRENT_JOBS_KEY] ?? $tree[self::DAEMONS][$daemon][self::QUEUE_MAX_CONCURRENT_JOBS_KEY] ?? $tree[self::QUEUE_MAX_CONCURRENT_JOBS_KEY],
            self::QUEUE_MAX_RETENTION_DAYS_KEY          => $config[self::QUEUE_MAX_RETENTION_DAYS_KEY] ?? $tree[self::DAEMONS][$daemon][self::QUEUE_MAX_RETENTION_DAYS_KEY] ?? $tree[self::QUEUE_MAX_RETENTION_DAYS_KEY],
            self::QUEUE_RETRY_STALE_JOBS_KEY            => $config[self::QUEUE_RETRY_STALE_JOBS_KEY] ?? $tree[self::DAEMONS][$daemon][self::QUEUE_RETRY_STALE_JOBS_KEY] ?? $tree[self::QUEUE_RETRY_STALE_JOBS_KEY],
            self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY => $config[self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY] ?? $tree[self::DAEMONS][$daemon][self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY] ?? $tree[self::QUEUE_RUNNING_JOBS_CHECK_INTERVAL_KEY],
        ];
    }
}
