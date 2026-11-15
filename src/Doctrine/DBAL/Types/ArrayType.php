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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Doctrine\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * The `array` type removed from doctrine/dbal 4.
 *
 * The bundle's entities store PHP-serialized values (the input of a Job, its retry strategy, the config of a Daemon)
 * in columns mapped with the `array` type. doctrine/dbal 4 dropped that type: this one stores and reads the values
 * exactly as doctrine/dbal 3 did, so existing rows keep being readable. It is registered under the name `array` only
 * when doctrine/dbal does not provide it any more (see SHQCommandsQueuesExtension::prepend()).
 */
final class ArrayType extends Type
{
    /** @var string */
    public const NAME = 'array';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return serialize($value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return null;
        }

        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (false === \is_string($value)) {
            throw new ConversionException(\sprintf('Could not convert database value of type "%s" to Doctrine Type "%s".', get_debug_type($value), self::NAME));
        }

        $converted = @unserialize($value);
        if (false === $converted && 'b:0;' !== $value) {
            throw new ConversionException(\sprintf('Could not convert database value "%s" to Doctrine Type "%s".', substr($value, 0, 32), self::NAME));
        }

        return $converted;
    }

    /**
     * Required by doctrine/dbal 3, where the type is never registered because doctrine/dbal provides its own.
     */
    public function getName(): string
    {
        return self::NAME;
    }
}
