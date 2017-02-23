<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;

/**
 * {@inheritdoc}
 */
class JobRepository extends EntityRepository
{
    /** @var  array $config */
    private $config;

    /**
     * @param array $config
     */
    public function configure(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param int $id
     *
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
     * @param string $queueName
     *
     * @return null|Job
     */
    public function findNextRunnableJob(string $queueName)
    {
        // Collects the Jobs that have to be excluded from the next findNextJob() call
        $excludedJobs = [];

        while (null !== $job = $this->findNextJob($queueName, $excludedJobs)) {
            // If it can be run...
            if (false === $job->hasNotFinishedParentJobs()) {
                // Refresh the Job to get loaded again child and parent Jobs that were eventually detached
                $this->getEntityManager()->refresh($job);

                // ... Return it
                return $job;
            }

            // The Job cannot be run
            $excludedJobs[] = $job->getId();

            // Remove it from the Entity Manager to free some memory
            $this->getEntityManager()->detach($job);
        }

        return null;
    }

    /**
     * @return int
     */
    public function countStaleJobs()
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();

        $queryBuilder->select('COUNT(j)')->from('SHQCommandsQueuesBundle:Job', 'j')
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq('j.status', ':running'),
                    $queryBuilder->expr()->eq('j.status', ':pending')
                )
            )
            ->setParameter('running', Job::STATUS_PENDING)->setParameter('pending', Job::STATUS_RUNNING);

        // Configure the queues to include or to exclude
        $this->configureQueues($queryBuilder);

        return (int) $queryBuilder->getQuery()
            ->getOneOrNullResult()['1'];
    }

    /**
     * @param array $knownAsStale
     * @return Job
     */
    public function findNextStaleJob(array $knownAsStale)
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();
        $queryBuilder->select('j')->from('SHQCommandsQueuesBundle:Job', 'j')
            // The status MUST be NEW (just inserted) or PENDING (waiting for the process to start)
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq('j.status', ':running'),
                    $queryBuilder->expr()->eq('j.status', ':pending')
                )
            )
            ->setParameter('running', Job::STATUS_PENDING)->setParameter('pending', Job::STATUS_RUNNING);

        // If there are already known stale Jobs...
        if (false === empty($knownAsStale)) {
            // The ID hasn't to be one of them
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('j.id', ':knownAsStale')
            )->setParameter('knownAsStale', $knownAsStale, Connection::PARAM_INT_ARRAY);
        }

        $this->configureQueues($queryBuilder);

        return $queryBuilder->getQuery()->setMaxResults(1)->getOneOrNullResult();
    }

    /**
     * Configures in the query the queues to include.
     *
     * @param QueryBuilder $queryBuilder
     */
    private function configureQueues(QueryBuilder $queryBuilder)
    {
        // Set the queues to include
        if (isset($this->config['included_queues'])) {
            $queryBuilder->andWhere($queryBuilder->expr()->in('j.queue', ':includedQueues'))
                ->setParameter('includedQueues', $this->config['included_queues'], Connection::PARAM_STR_ARRAY);
        }
    }

    /**
     * Finds the next Job to process.
     *
     * @param string $queueName
     * @param array $excludedJobs The Jobs that have to be excluded from the SELECT
     *
     * @return Job|null
     */
    private function findNextJob(string $queueName, array $excludedJobs = [])
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();
        $queryBuilder->select('j')->from('SHQCommandsQueuesBundle:Job', 'j')
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
            )->setParameter('now', new \DateTime(), 'datetime')
            ->andWhere($queryBuilder->expr()->eq('j.queue', ':queue'))->setParameter('queue', $queueName);

        // If there are excluded Jobs...
        if (false === empty($excludedJobs)) {
            // The ID hasn't to be one of them
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('j.id', ':excludedJobs')
            )->setParameter('excludedJobs', $excludedJobs, Connection::PARAM_INT_ARRAY);
        }

        $this->configureQueues($queryBuilder);

        return $queryBuilder->getQuery()->setMaxResults(1)->getOneOrNullResult();
    }
}
