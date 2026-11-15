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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use PHPUnit\Framework\TestCase;
use SerendipityHQ\Bundle\CommandsQueuesBundle\DependencyInjection\SHQCommandsQueuesExtension;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Doctrine\DBAL\Types\ArrayType;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SHQCommandsQueuesExtensionTest extends TestCase
{
    public function testRegistersTheArrayTypeOnlyWhenDoctrineDbalLacksIt(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());

        (new SHQCommandsQueuesExtension())->prepend($container);

        $expected = \class_exists(\Doctrine\DBAL\Types\ArrayType::class)
            ? []
            : [['dbal' => ['types' => ['array' => ArrayType::class]]]];

        self::assertSame($expected, $container->getExtensionConfig('doctrine'));
    }

    public function testDoesNothingWithoutDoctrineBundle(): void
    {
        $container = new ContainerBuilder();

        (new SHQCommandsQueuesExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }
}
