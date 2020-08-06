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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Tests\Util;

use PHPUnit\Framework\TestCase;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\InputParser;

/**
 * {@inheritdoc}
 */
final class InputParserTest extends TestCase
{
    public function testIsArgument(): void
    {
        self::assertTrue(InputParser::isArgument('argument'));
        self::assertFalse(InputParser::isArgument('--option'));
        self::assertFalse(InputParser::isArgument('-shortcut'));
    }

    public function testIsOption(): void
    {
        self::assertFalse(InputParser::isOption('argument'));
        self::assertTrue(InputParser::isOption('--option'));
        self::assertFalse(InputParser::isOption('-shortcut'));
    }

    public function testIsShortcut(): void
    {
        self::assertFalse(InputParser::isShortcut('argument'));
        self::assertFalse(InputParser::isShortcut('--option'));
        self::assertTrue(InputParser::isShortcut('-shortcut'));
    }

    public function testParseStringWithCommand(): void
    {
        $test   = 'command:name first_argument alphabetical_argument --option-without-equal option-value-without-equal 1 -sbool --option-with-equal=option-value-with-equal --option-boolean -s shortcut-value';
        $result = InputParser::parseInput($test);

        self::assertEquals($this->getExpected(), $result);
    }

    public function testParseStringWithoutCommand(): void
    {
        $test                = 'first_argument alphabetical_argument --option-without-equal option-value-without-equal 1 -sbool --option-with-equal=option-value-with-equal --option-boolean -s shortcut-value';
        $result              = InputParser::parseInput($test, false);
        $expected            = $this->getExpected();
        $expected['command'] = null;

        self::assertEquals($expected, $result);
    }

    public function testParseAnArray(): void
    {
        $test = [
            'command' => 'command:name',
            'first_argument', 'alphabetical_argument', '1',
            '--option-without-equal' => 'option-value-without-equal',
            '--option-with-equal'    => 'option-value-with-equal',
            '--option-boolean'       => null,
            '-sbool'                 => null,
            '-s'                     => 'shortcut-value',
        ];

        $result = InputParser::parseInput($test, false);

        self::assertEquals($this->getExpected(), $result);
    }

    public function testParseAMixedArray(): void
    {
        $test = [
            'command' => 'command:name',
            'first_argument', 'alphabetical_argument', '1',
            'options' => [
                    '--option-without-equal' => 'option-value-without-equal',
                    '--option-with-equal'    => 'option-value-with-equal',
                    '--option-boolean'       => null,
                ],
            '-sbool'     => null,
                    '-s' => 'shortcut-value',
        ];

        $result = InputParser::parseInput($test, false);

        self::assertEquals($this->getExpected(), $result);
    }

    public function testParseWithAlreadyParsedInput(): void
    {
        $expected            = $this->getExpected();
        $expected['command'] = null;

        $result = InputParser::parseInput($expected, false);

        self::assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    private function getExpected(): array
    {
        return [
            'command'   => 'command:name',
            'arguments' => [
                    'first_argument', 'alphabetical_argument', '1',
                ],
            'options' => [
                    '--option-without-equal' => 'option-value-without-equal',
                    '--option-with-equal'    => 'option-value-with-equal',
                    '--option-boolean'       => null,
                ],
            'shortcuts' => [
                    '-sbool' => null,
                    '-s'     => 'shortcut-value',
                ],
        ];
    }
}
