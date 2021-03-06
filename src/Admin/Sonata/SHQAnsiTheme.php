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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Admin\Sonata;

use SensioLabs\AnsiConverter\Theme\Theme;

/**
 * {@inheritdoc}
 */
final class SHQAnsiTheme extends Theme
{
    /**
     * {@inheritdoc}
     */
    public function asArray(): array
    {
        return [
            // normal
            'black'   => '#262626',
            'red'     => '#FF6B68',
            'green'   => '#A8C023',
            'yellow'  => '#D6BF55',
            'blue'    => '#4175EC',
            'magenta' => '#AE8ABE',
            'cyan'    => '#299999',
            'white'   => '#eee8d5',

            // bright
            'brblack'   => '#002b36',
            'brred'     => '#FF8785',
            'brgreen'   => '#A8C023',
            'bryellow'  => '#FFFF00',
            'brblue'    => '#7EAEF1',
            'brmagenta' => '#FF99FF',
            'brcyan'    => '#93a1a1',
            'brwhite'   => '#fdf6e3',
        ];
    }
}
