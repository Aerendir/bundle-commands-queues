<?php

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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Repository;

use Doctrine\ORM\EntityRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;

/**
 * {@inheritdoc}
 */
class DaemonRepository extends EntityRepository
{
    /**
     * Finds the next Job to process.
     *
     * @param Daemon $currentDaemon The current running Daemon: it has to not be considered!
     *
     * @return Daemon|null
     */
    public function findNextAlive(Daemon $currentDaemon): ?Daemon
    {
        $queryBuilder = $this->_em->createQueryBuilder();
        $queryBuilder->select('d')->from('SHQCommandsQueuesBundle:Daemon', 'd')
            ->where($queryBuilder->expr()->andX(
                $queryBuilder->expr()->isNull('d.diedOn'),
                $queryBuilder->expr()->neq('d.pid', $currentDaemon->getPid())
            ));

        return $queryBuilder->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
