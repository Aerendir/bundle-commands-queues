<?php

/*
 * This file is part of the AWS SES Monitor Bundle.
 *
 * @author Adamo Aerendir Crespi <hello@aerendir.me>
 * @author Audrius Karabanovas <audrius@karabanovas.net>
 */

namespace SerendipityHQ\Bundle\QueuesBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @author Audrius Karabanovas <audrius@karabanovas.net>
 * @author Adamo Aerendir Crespi <hello@aerendir.me>
 *
 * {@inheritdoc}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * The list of supported ORM drivers.
     *
     * @return array
     */
    public static function getSupportedDrivers()
    {
        return ['orm'];
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode    = $treeBuilder->root('queues');

        $rootNode
            ->children()
                ->scalarNode('db_driver')
                    ->validate()
                        ->ifNotInArray(self::getSupportedDrivers())
                        ->thenInvalid('The driver %s is not supported. Please choose one of ' . json_encode(self::getSupportedDrivers()))
                    ->end()
                    ->cannotBeOverwritten()
                    ->defaultValue('orm')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('model_manager_name')->defaultNull()->end()
                ->integerNode('max_runtime')->defaultValue(100)->end()
                ->integerNode('max_concurrent_jobs')->defaultValue(1)->end()
                ->integerNode('idle_time')->defaultValue(10)->end()
                ->scalarNode('worker_name')->defaultValue('DefaultWorker')->end();

        return $treeBuilder;
    }
}
