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

use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\InternalMarkAsCancelledCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\RunCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\TestFailingJobsCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\TestFakeCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Command\TestRandomJobsCommand;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Config\DaemonConfig;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Doctrine\DBAL\Types\ArrayType;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\JobsManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\QueuesDaemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use Sonata\AdminBundle\SonataAdminBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class SHQCommandsQueuesExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Registers the `array` Doctrine type the entities are mapped with, when doctrine/dbal (4+) does not provide it.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (\class_exists(\Doctrine\DBAL\Types\ArrayType::class) || false === $container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', ['dbal' => ['types' => [ArrayType::NAME => ArrayType::class]]]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        // The Jobs Manager
        $jobsManagerDefinition = (new Definition(JobsManager::class, [
            $container->getParameter('kernel.project_dir'),
        ]))->setPublic(false);
        $container->setDefinition(JobsManager::class, $jobsManagerDefinition);

        // The Jobs Marker
        $jobsMarkerDefinition = (new Definition(JobsMarker::class))->setPublic(false)->setAutowired(true);
        $container->setDefinition(JobsMarker::class, $jobsMarkerDefinition);

        // The Profiler
        $profilerDefinition = (new Definition(Profiler::class))->setPublic(false);
        $container->setDefinition(Profiler::class, $profilerDefinition);

        // The Daemon
        $daemonConfigDefinition = (new Definition(DaemonConfig::class, [$config['daemons'], $config['queues']]))
            ->setPublic(false);

        $daemonDefinition = (new Definition(QueuesDaemon::class, [$daemonConfigDefinition]))->setPublic(false)->setAutowired(true);
        $container->setDefinition(QueuesDaemon::class, $daemonDefinition);

        // The queues:run command
        $runCommandDefinition = (new Definition(RunCommand::class))->addTag('console.command')->setAutowired(true);
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

        if (\class_exists(SonataAdminBundle::class)) {
            $sonataLoader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Admin/Sonata/Resources/config'));
            $sonataLoader->load('sonata_admin.yaml');
        }
    }
}
