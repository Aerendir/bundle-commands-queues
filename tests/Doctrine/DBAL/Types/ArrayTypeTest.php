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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Tests\Doctrine\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\TestCase;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Doctrine\DBAL\Types\ArrayType;
use SerendipityHQ\Component\ThenWhen\Strategy\LiveStrategy;

final class ArrayTypeTest extends TestCase
{
    private ArrayType $type;
    private AbstractPlatform $platform;

    #[\Override]
    protected function setUp(): void
    {
        $this->type     = new ArrayType();
        $this->platform = $this->createStub(AbstractPlatform::class);
    }

    public function testStoresValuesAsPhpSerializedStrings(): void
    {
        $value = ['options' => ['--id' => '1'], 'arguments' => ['an-argument']];

        self::assertSame(serialize($value), $this->type->convertToDatabaseValue($value, $this->platform));
    }

    public function testReadsBackWhatItStores(): void
    {
        $strategy = new LiveStrategy(3);
        $stored   = $this->type->convertToDatabaseValue($strategy, $this->platform);

        self::assertEquals($strategy, $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testReadsFalse(): void
    {
        self::assertFalse($this->type->convertToPHPValue(serialize(false), $this->platform));
    }

    public function testKeepsNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testRejectsValuesThatAreNotSerialized(): void
    {
        $this->expectException(ConversionException::class);

        $this->type->convertToPHPValue('not serialized', $this->platform);
    }
}
