<?php

namespace SerendipityHQ\Bundle\QueuesBundle\DependencyInjection\CompilerPass;

use SerendipityHQ\Bundle\QueuesBundle\Service\JobsManager;
use SerendipityHQ\Bundle\QueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\QueuesBundle\Util\Profiler;
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
            $container->getParameter('kernel.root_dir'), $container->findDefinition('queues.entity_manager'),
        ]);

        // The Jobs Marker
        $jobsMarkerDefinition = new Definition(JobsMarker::class, [
            $container->findDefinition('queues.entity_manager'),
        ]);

        // The Profiler
        $profilerDefinition = new Definition(Profiler::class);

        // The Daemon
        $daemonDefinition = $container->findDefinition('queues.do_not_use.daemon');
        $daemonDefinition->addMethodCall('setDependencies', [
            $container->getParameter('queues.config'),
            $container->findDefinition('queues.entity_manager'),
            $jobsManagerDefinition,
            $jobsMarkerDefinition,
            $profilerDefinition,
        ]);
    }
}
