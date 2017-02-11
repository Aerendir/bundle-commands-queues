<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Repository;

use Doctrine\ORM\EntityRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;

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
    public function findNextAlive(Daemon $currentDaemon)
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
