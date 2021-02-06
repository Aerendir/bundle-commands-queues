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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Repository;

use Doctrine\ORM\EntityRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;

/**
 * {@inheritdoc}
 */
final class DaemonRepository extends EntityRepository
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
