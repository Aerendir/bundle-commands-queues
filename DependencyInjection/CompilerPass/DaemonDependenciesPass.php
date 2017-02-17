<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection\CompilerPass;

use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\JobsManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\QueuesDaemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\Profiler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Sets the Daemon's dependencies.
 */
class DaemonDependenciesPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        // The Jobs Manager
        $jobsManagerDefinition = new Definition(JobsManager::class, [
            $container->getParameter('kernel.root_dir'),
        ]);

        // The Jobs Marker
        $jobsMarkerDefinition = new Definition(JobsMarker::class, [
            $container->findDefinition('commands_queues.do_not_use.entity_manager'),
        ]);
        $container->setDefinition('commands_queues.do_not_use.jobs_marker', $jobsMarkerDefinition);

        // The Profiler
        $profilerDefinition = new Definition(Profiler::class);

        // The Daemon
        $daemonDefinition = new Definition(QueuesDaemon::class, [
            $container->getParameter('commands_queues.config'),
            $container->findDefinition('commands_queues.do_not_use.entity_manager'),
            $jobsManagerDefinition,
            $container->findDefinition('commands_queues.do_not_use.jobs_marker'),
            $profilerDefinition
        ]);
        $container->setDefinition('commands_queues.do_not_use.daemon', $daemonDefinition);
    }
}
