<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Repository;

use Doctrine\ORM\EntityRepository;
use SerendipityHQ\Bundle\QueuesBundle\Entity\Daemon;
use SerendipityHQ\Bundle\QueuesBundle\Entity\Job;

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
        $queryBuilder->select('d')->from('QueuesBundle:Daemon', 'd')
            ->where($queryBuilder->expr()->andX(
                $queryBuilder->expr()->isNull('d.diedOn'),
                $queryBuilder->expr()->neq('d.pid', $currentDaemon->getPid())
            ));

        return $queryBuilder->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}