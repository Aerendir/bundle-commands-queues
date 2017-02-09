<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;
use SerendipityHQ\Bundle\QueuesBundle\Entity\Job;

/**
 * {@inheritdoc}
 */
class JobRepository extends EntityRepository
{
    /**
     * @param int $id
     * @return null|object|Job
     */
    public function findOneById(int $id)
    {
        return parent::findOneBy(['id' => $id]);
    }

    /**
     * Returns a Job that can be run.
     *
     * A Job can be run if it hasn't a startDate in the future and if its parent Jobs are already terminated with
     * success.
     *
     * @return null|Job
     */
    public function findNextRunnableJob()
    {
        // Collects the Jobs that have to be excluded from the next findNextJob() call
        $excludedJobs = [];

        while (null !== $job = $this->findNextJob($excludedJobs)) {
            // If it can be run and its lock is successfully acquired...
            if (false === $job->hasNotFinishedParentJobs()) {
                // ... Return it
                return $job;
            }

            // The Job cannot be run or its lock cannot be acquired
            $excludedJobs[] = $job->getId();

            // Remove it from the Entity Manager to free some memory
            $this->_em->detach($job);
        }

        return null;
    }

    /**
     * Finds the next Job to process.
     *
     * @param array $excludedJobs The Jobs that have to be excluded from the SELECT
     *
     * @return Job|null
     */
    private function findNextJob(array $excludedJobs = [])
    {
        $queryBuilder = $this->_em->createQueryBuilder();
        $queryBuilder->select('j')->from('QueuesBundle:Job', 'j')
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.createdAt', 'ASC')
            // The status MUST be NEW
            ->where($queryBuilder->expr()->eq('j.status', ':status'))->setParameter('status', Job::STATUS_NEW)
            // It hasn't an executeAfterTime set or the set time is in the past
            ->andWhere(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->isNull('j.executeAfterTime'),
                    $queryBuilder->expr()->lt('j.executeAfterTime', ':now')
                )
            )->setParameter('now', new \DateTime(), 'datetime');

        // If there are excluded Jobs...
        if (false === empty($excludedJobs)) {
            // The ID hasn't to be one of them
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('j.id', ':excludedJobs')
            )->setParameter('excludedJobs', $excludedJobs, Connection::PARAM_INT_ARRAY);
        }

        return $queryBuilder->getQuery()->setMaxResults(1)->getOneOrNullResult();
    }
}
