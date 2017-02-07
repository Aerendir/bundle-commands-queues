<?php

namespace SerendipityHQ\Bundle\QueuesBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use SerendipityHQ\Bundle\QueuesBundle\DependencyInjection\CompilerPass\DaemonDependenciesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * {@inheritdoc}
 */
class QueuesBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $modelDir = $this->getPath().'/Resources/config/doctrine/mappings';
        $mappings = [
            $modelDir => 'SerendipityHQ\Bundle\QueuesBundle\Entity',
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
            ['queues.model_manager_name'],
            'queues.backend_orm',
            ['QueuesBundle' => 'SerendipityHQ\Bundle\QueuesBundle\Entity']
        );
    }
}
