<?php

declare(strict_types=1);

/*
 * This file is part of the SHQCommandsQueuesBundle.
 *
 * Copyright Adamo Aerendir Crespi 2017.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author    Adamo Aerendir Crespi <hello@aerendir.me>
 * @copyright Copyright (C) 2017 Aerendir. All rights reserved.
 * @license   MIT License.
 */

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Util;

use function Safe\sprintf;

/**
 * An helper class to manage the input of a command.
 *
 * You can pass a string or an array with the result of this Class.
 *
 * Passing an array that contains both the result of this class and other
 */
class InputParser
{
    /** @var string|null $foundArgument */
    private static $foundArgument;

    /** @var string|null $foundOption */
    private static $foundOption;

    /** @var string|null $foundShortcut */
    private static $foundShortcut;

    /** @var array|null $preparedInput */
    private static $preparedInput;

    /** @var array $preparedInput */
    private static $defaultPreparedInput = [
        'command'   => null,
        'arguments' => null,
        'options'   => null,
        'shortcuts' => null,
    ];

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
     * @suppress PhanTypeMismatchDimFetch
     *
     * @return array
     */
    public static function parseInput($input = [], bool $hasCommand = true): array
    {
        self::$preparedInput = self::$defaultPreparedInput;
        $wasString           = false;

        if (null === $input) {
            return self::$preparedInput;
        }

        if (is_string($input)) {
            $input = str_replace('=', ' ', $input);

            // Transform into an array
            $input = explode(' ', $input);

            // And remove leading and trailing spaces
            $input     = array_map('trim', $input);
            $wasString = true;
        }

        if ($hasCommand && $wasString) {
            $commandKey                     = array_key_first($input);
            self::$preparedInput['command'] = $input[$commandKey];
            unset($input[$commandKey]);
        }

        if (array_key_exists('command', $input)) {
            self::$preparedInput['command'] = $input['command'];
            unset($input['command']);
        }

        if (array_key_exists('arguments', $input)) {
            self::$preparedInput['arguments'] = $input['arguments'];
            unset($input['arguments']);
        }

        if (array_key_exists('options', $input)) {
            self::$preparedInput['options'] = $input['options'];
            unset($input['options']);
        }

        if (array_key_exists('shortcuts', $input)) {
            self::$preparedInput['shortcuts'] = $input['shortcuts'];
            unset($input['shortcuts']);
        }

        foreach ($input as $key => $value) {
            if (null !== $key && false === is_numeric($key)) {
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
        if (array_key_exists('options', self::$preparedInput) && null !== self::$preparedInput['options']) {
            ksort(self::$preparedInput['options'], SORT_NATURAL);
        }

        if (array_key_exists('shortcuts', self::$preparedInput) && null !== self::$preparedInput['shortcuts']) {
            ksort(self::$preparedInput['shortcuts'], SORT_NATURAL);
        }

        return self::$preparedInput;
    }

    /**
     * @param array|null $input
     * @param bool       $withCommand
     *
     * @return string|null
     */
    public static function stringify(?array $input = [], $withCommand = false): ?string
    {
        $preparedInput    = '';
        $stringifyClosure = static function ($value, $key) {
            return sprintf('%s=%s', $key, $value);
        };

        if (null === $input) {
            return null;
        }

        if ($withCommand && array_key_exists('command', $input)) {
            $preparedInput .= $input['command'];
        }

        if (array_key_exists('arguments', $input) && null !== $input['arguments']) {
            $arguments = implode(' ', $input['arguments']);
            $preparedInput .= ' ' . $arguments;
        }

        if (array_key_exists('options', $input) && null !== $input['options']) {
            $optionsKeys = array_keys($input['options']);
            $options     = array_map($stringifyClosure, $input['options'], $optionsKeys);
            $options     = implode(' ', $options);
            $preparedInput .= ' ' . $options;
        }

        if (array_key_exists('shortcuts', $input) && null !== $input['shortcuts']) {
            $shortcutsKeys = array_keys($input['shortcuts']);
            $shortcuts     = array_map($stringifyClosure, $input['shortcuts'], $shortcutsKeys);
            $shortcuts     = implode(' ', $shortcuts);
            $preparedInput .= ' ' . $shortcuts;
        }

        $preparedInput = trim($preparedInput);

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
        return 0 === strpos($option, '--');
    }

    /**
     * @param string $shortcut
     *
     * @return bool
     */
    public static function isShortcut(string $shortcut): bool
    {
        return false === self::isOption($shortcut) && 0 === strpos($shortcut, '-');
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
            self::$preparedInput['options'][$value] = null;
            self::$foundOption                      = $value;
            self::$foundShortcut                    = null;
        }

        if (self::isShortcut($value)) {
            self::$preparedInput['shortcuts'][$value] = null;
            self::$foundShortcut                      = $value;
            self::$foundOption                        = null;
        }

        if (null !== self::$foundArgument) {
            self::$preparedInput['arguments'][] = self::$foundArgument;
            self::$foundArgument                = null;
        }

        if (null !== self::$foundOption && self::$foundOption !== $value && false === self::isOption($value) && false === self::isShortcut($value)) {
            self::$preparedInput['options'][self::$foundOption] = $value;
            self::$foundOption                                  = null;
        }

        if (null !== self::$foundShortcut && self::$foundShortcut !== $value && false === self::isOption($value) && false === self::isShortcut($value)) {
            self::$preparedInput['shortcuts'][self::$foundShortcut] = $value;
            self::$foundShortcut                                    = null;
        }
    }
}
