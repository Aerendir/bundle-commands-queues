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

use DateTime;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;

/**
 * A set of helper methods to use with the Jobs.
 */
class JobsUtil
{
    /** @var string */
    public const TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param Job    $job
     * @param string $getTime
     *
     * @return string
     */
    public static function getFormattedTime(Job $job, string $getTime): string
    {
        $time = 'XXXX-XX-XX XX:XX:XX';

        if (false === method_exists($job, $getTime)) {
            return $time;
        }

        $jobTime = $job->$getTime();
        if ($jobTime instanceof DateTime) {
            $time = $jobTime->format(self::TIME_FORMAT);
        }

        return $time;
    }
}
