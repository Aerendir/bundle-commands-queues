<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Repository;

use Doctrine\ORM\EntityRepository;
use SerendipityHQ\Bundle\QueuesBundle\Entity\Job;

/**
 * {@inheritdoc}
 */
class JobRepository extends EntityRepository
{
    /**
     * Finds the next Job to process.
     *
     * @return Job|null
     */
    public function findNextJob()
    {
        $queryBuilder = $this->_em->createQueryBuilder();
        $queryBuilder->select('j')->from('QueuesBundle:Job', 'j')
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.createdAt', 'ASC')
            ->where($queryBuilder->expr()->eq('j.status', ':status'))->setParameter('status', Job::STATUS_NEW)
            ->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->isNull('j.executeAfterTime'),
                $queryBuilder->expr()->lt('j.executeAfterTime', ':now')
            ))->setParameter('now', new \DateTime(), 'datetime');

        return $queryBuilder->getQuery()->setMaxResults(1)->getOneOrNullResult();
    }
}
