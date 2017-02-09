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

        $modelDir = $this->getPath().'/Resources/config/doctrine/mappings';
        $mappings = [
            $modelDir => 'SerendipityHQ\Bundle\CommandsQueuesBundle\Entity',
        ];

        $ormCompilerClass = DoctrineOrmMappingsPass::class;
        if (class_exists($ormCompilerClass)) {
            $container->addCompilerPass(
                $this->getYamlMappingDriver($mappings)
            );
        }

        $container->addCompilerPass(new DaemonDependenciesPass());
    }

    /**
     * @param array $mappings
     *
     * @return DoctrineOrmMappingsPass
     */
    private function getYamlMappingDriver(array $mappings)
    {
        return DoctrineOrmMappingsPass::createYamlMappingDriver(
            $mappings,
            ['commands_queues.model_manager_name'],
            'commands_queues.backend_orm',
            ['SHQCommandsQueuesBundle' => 'SerendipityHQ\Bundle\CommandsQueuesBundle\Entity']
        );
    }
}
