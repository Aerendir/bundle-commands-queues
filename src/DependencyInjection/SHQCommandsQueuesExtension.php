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

use Exception;
use Safe\Exceptions\StringsException;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\InternalMarkAsCancelledCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\RunCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\TestFailingJobsCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\TestFakeCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\TestRandomJobsCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\JobsManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\QueuesDaemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use Sonata\AdminBundle\SonataAdminBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * {@inheritdoc}
 */
class SHQCommandsQueuesExtension extends Extension
{
    /**
     * {@inheritdoc}
     *
     * @throws StringsException
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        // Set parameters in the container
        $container->setParameter('commands_queues.model_manager_name', $config['model_manager_name']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        // load db_driver container configuration
        $loader->load(\Safe\sprintf('%s.yml', $config['db_driver']));

        // The Jobs Manager
        $jobsManagerDefinition = (new Definition(JobsManager::class, [
            $container->getParameter('kernel.root_dir'),
        ]))->setPublic(false);

        // The Jobs Marker
        $jobsMarkerDefinition = (new Definition(JobsMarker::class, [
            $container->findDefinition('shq_commands_queues.do_not_use.entity_manager'),
        ]))->setPublic(false);
        $container->setDefinition('shq_commands_queues.do_not_use.jobs_marker', $jobsMarkerDefinition);

        // The Profiler
        $profilerDefinition = (new Definition(Profiler::class))->setPublic(false);

        // The Daemon
        $daemonConfigDefinition = (new Definition(DaemonConfig::class, [$config['daemons'], $config['queues']]))
            ->setPublic(false);

        $daemonDefinition = (new Definition(QueuesDaemon::class, [
            $daemonConfigDefinition,
            $container->findDefinition('shq_commands_queues.do_not_use.entity_manager'),
            $jobsManagerDefinition,
            $jobsMarkerDefinition,
            $profilerDefinition,
        ]))->setPublic(false);

        // The queues:run command
        $runCommandDefinition = (new Definition(RunCommand::class, [
            $daemonDefinition,
            $container->findDefinition('shq_commands_queues.do_not_use.entity_manager'),
            $jobsMarkerDefinition,
        ]))->addTag('console.command');
        $container->setDefinition(RunCommand::class, $runCommandDefinition);

        // The queues:test:failing-jobs command
        $testFailingJobsCommandDefinition = (new Definition(TestFailingJobsCommand::class, [
            $container->findDefinition('queues'),
        ]))->addTag('console.command');
        $container->setDefinition(TestFailingJobsCommand::class, $testFailingJobsCommandDefinition);

        // The queues:test:random-jobs command
        $testRandomJobsCommandDefinition = (new Definition(TestRandomJobsCommand::class))->addTag('console.command')->setAutowired(true);
        $container->setDefinition(TestRandomJobsCommand::class, $testRandomJobsCommandDefinition);

        // The queues:test:fake command
        $testFakeCommandDefinition = (new Definition(TestFakeCommand::class))->addTag('console.command')->setAutowired(true);
        $container->setDefinition(TestFakeCommand::class, $testFakeCommandDefinition);

        // The queues:internal:mark-as-cancelled command
        $internalMarkAsCancelledCommandDefinition = (new Definition(InternalMarkAsCancelledCommand::class))->addTag('console.command')->setAutowired(true);
        $container->setDefinition(InternalMarkAsCancelledCommand::class, $internalMarkAsCancelledCommandDefinition);

        if (class_exists(SonataAdminBundle::class)) {
            $sonataLoader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Admin/Sonata/Resources/config'));
            $sonataLoader->load('sonata_admin.yaml');
        }
    }
}
