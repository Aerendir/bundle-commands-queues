<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection\CompilerPass\DaemonDependenciesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * {@inheritdoc}
 */
class SHQCommandsQueuesBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new DaemonDependenciesPass());
    }
}
