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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use InvalidArgumentException;
use Safe\Exceptions\StringsException;
use function Safe\sprintf;

/**
 * An helper class to manage the input of a command.
 *
 * You can pass a string or an array with the result of this Class.
 *
 * Passing an array that contains both the result of this class and other
 */
final class InputParser
{
    /** @var string|null $foundArgument */
    private static $foundArgument;

    /** @var string|null $foundOption */
    private static $foundOption;

    /** @var string|null $foundShortcut */
    private static $foundShortcut;

    /** @var array|null $preparedInput */
    private static $preparedInput;
    /** @var null[] $preparedInput */
    private const DEFAULT_PREPARED_INPUT = [
        self::COMMAND   => null,
        self::ARGUMENTS => null,
        self::OPTIONS   => null,
        self::SHORTCUTS => null,
    ];
    /**
     * @var string
     */
    private const COMMAND = 'command';
    /**
     * @var string
     */
    private const ARGUMENTS = 'arguments';
    /**
     * @var string
     */
    private const OPTIONS = 'options';
    /**
     * @var string
     */
    private const SHORTCUTS = 'shortcuts';

    /**
     * Ensures the $arguments is only a string or an array.
     * If a string is passed, it is transformed into an array.
     * Then it reorder the arguments to get a unique signature to facilitate checks on existent Jobs.
     *
     * This suppresses a Phan error: check if it is fixed here https://github.com/phan/phan/issues/2706
     *
     * @param array|string|null $input
     * @param bool              $hasCommand If the input contains also the command name or not
     *
     * @throws StringsException
     *
     * @return array
     * @suppress PhanTypeMismatchDimFetch
     */
    public static function parseInput($input = [], bool $hasCommand = true): array
    {
        self::$preparedInput = self::DEFAULT_PREPARED_INPUT;
        $wasString           = false;

        if (null === $input) {
            return self::$preparedInput;
        }

        if (\is_string($input)) {
            $input = \str_replace('=', ' ', $input);

            // Transform into an array
            $input = \explode(' ', $input);

            // And remove leading and trailing spaces
            $input     = \array_map('trim', $input);
            $wasString = true;
        }

        if ($hasCommand && $wasString) {
            $commandKey                     = \array_key_first($input);

            if (false === self::isArgument($input[$commandKey])) {
                throw new InvalidArgumentException(sprintf('The given command "%s" seems not to be a valid command.', $input[$commandKey]));
            }

            self::$preparedInput[self::COMMAND] = $input[$commandKey];
            unset($input[$commandKey]);
        }

        if (\array_key_exists(self::COMMAND, $input)) {
            self::$preparedInput[self::COMMAND] = $input[self::COMMAND];
            unset($input[self::COMMAND]);
        }

        if (\array_key_exists(self::ARGUMENTS, $input)) {
            self::$preparedInput[self::ARGUMENTS] = $input[self::ARGUMENTS];
            unset($input[self::ARGUMENTS]);
        }

        if (\array_key_exists(self::OPTIONS, $input)) {
            self::$preparedInput[self::OPTIONS] = $input[self::OPTIONS];
            unset($input[self::OPTIONS]);
        }

        if (\array_key_exists(self::SHORTCUTS, $input)) {
            self::$preparedInput[self::SHORTCUTS] = $input[self::SHORTCUTS];
            unset($input[self::SHORTCUTS]);
        }

        foreach ($input as $key => $value) {
            if (null !== $key && false === \is_numeric($key)) {
                self::parseValue($key);
            }

            if (null !== $value) {
                self::parseValue($value);
            }
        }

        self::$foundArgument = null;
        self::$foundOption   = null;
        self::$foundShortcut = null;

        // Don't reorder the arguments as their order is relevant
        if (\array_key_exists(self::OPTIONS, self::$preparedInput) && null !== self::$preparedInput[self::OPTIONS]) {
            \Safe\ksort(self::$preparedInput[self::OPTIONS], SORT_NATURAL);
        }

        if (\array_key_exists(self::SHORTCUTS, self::$preparedInput) && null !== self::$preparedInput[self::SHORTCUTS]) {
            \Safe\ksort(self::$preparedInput[self::SHORTCUTS], SORT_NATURAL);
        }

        return self::$preparedInput;
    }

    /**
     * @param array|null $input
     * @param bool       $withCommand
     *
     * @return string|null
     */
    public static function stringify(?array $input = [], bool $withCommand = false): ?string
    {
        $preparedInput    = '';
        $stringifyClosure = static function ($value, $key): string {
            return sprintf('%s=%s', $key, $value);
        };

        if (null === $input) {
            return null;
        }

        if ($withCommand && \array_key_exists(self::COMMAND, $input)) {
            $preparedInput .= $input[self::COMMAND];
        }

        if (\array_key_exists(self::ARGUMENTS, $input) && null !== $input[self::ARGUMENTS]) {
            $arguments = \implode(' ', $input[self::ARGUMENTS]);
            $preparedInput .= ' ' . $arguments;
        }

        if (\array_key_exists(self::OPTIONS, $input) && null !== $input[self::OPTIONS]) {
            $optionsKeys = \array_keys($input[self::OPTIONS]);
            $options     = \array_map($stringifyClosure, $input[self::OPTIONS], $optionsKeys);
            $options     = \implode(' ', $options);
            $preparedInput .= ' ' . $options;
        }

        if (\array_key_exists(self::SHORTCUTS, $input) && null !== $input[self::SHORTCUTS]) {
            $shortcutsKeys = \array_keys($input[self::SHORTCUTS]);
            $shortcuts     = \array_map($stringifyClosure, $input[self::SHORTCUTS], $shortcutsKeys);
            $shortcuts     = \implode(' ', $shortcuts);
            $preparedInput .= ' ' . $shortcuts;
        }

        $preparedInput = \trim($preparedInput);

        return '' !== $preparedInput ? $preparedInput : null;
    }

    /**
     * @param string $argument
     *
     * @return bool
     */
    public static function isArgument(string $argument): bool
    {
        return false === self::isOption($argument) && false === self::isShortcut($argument);
    }

    /**
     * @param string $option
     *
     * @return bool
     */
    public static function isOption(string $option): bool
    {
        return 0 === \strpos($option, '--');
    }

    /**
     * @param string $shortcut
     *
     * @return bool
     */
    public static function isShortcut(string $shortcut): bool
    {
        return false === self::isOption($shortcut) && 0 === \strpos($shortcut, '-');
    }

    /**
     * @param string $value
     */
    private static function parseValue(string $value): void
    {
        if (null === self::$foundOption && null === self::$foundShortcut && self::isArgument($value)) {
            self::$foundArgument = $value;
        }

        if (self::isOption($value)) {
            self::$preparedInput[self::OPTIONS][$value] = null;
            self::$foundOption                      = $value;
            self::$foundShortcut                    = null;
        }

        if (self::isShortcut($value)) {
            self::$preparedInput[self::SHORTCUTS][$value] = null;
            self::$foundShortcut                      = $value;
            self::$foundOption                        = null;
        }

        if (null !== self::$foundArgument) {
            self::$preparedInput[self::ARGUMENTS][] = self::$foundArgument;
            self::$foundArgument                = null;
        }

        if (null !== self::$foundOption && self::$foundOption !== $value && false === self::isOption($value) && false === self::isShortcut($value)) {
            self::$preparedInput[self::OPTIONS][self::$foundOption] = $value;
            self::$foundOption                                  = null;
        }

        if (null !== self::$foundShortcut && self::$foundShortcut !== $value && false === self::isOption($value) && false === self::isShortcut($value)) {
            self::$preparedInput[self::SHORTCUTS][self::$foundShortcut] = $value;
            self::$foundShortcut                                    = null;
        }
    }
}
