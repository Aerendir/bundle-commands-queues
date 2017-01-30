<?php

namespace SerendipityHQ\Bundle\QueuesBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
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

        $modelDir = realpath(__DIR__ . '/Resources/config/doctrine/mappings');
        $mappings = [
            $modelDir => 'SerendipityHQ\Bundle\QueuesBundle\Model',
        ];

        $ormCompilerClass = DoctrineOrmMappingsPass::class;
        if (class_exists($ormCompilerClass)) {
            $container->addCompilerPass(
                $this->getYamlMappingDriver($mappings)
            );
        }
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
            ['QueuesBundle' => 'SerendipityHQ\Bundle\QueuesBundle\Model']
        );
    }
}
