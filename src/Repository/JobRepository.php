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

use BadMethodCallException;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;
use Safe\Exceptions\StringsException;
use function Safe\sprintf;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Service\JobsManager;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * {@inheritdoc}
 */
final class JobRepository extends EntityRepository
{
    /** @var string */
    private const ARGUMENTS = 'arguments';

    /** @var string */
    private const OPTIONS = 'options';

    /** @var string */
    private const SHORTCUTS = 'shortcuts';

    /** @var string */
    private const ASC = 'ASC';

    /** @var array $config */
    private $config;

    /** @var SerendipityHQStyle $ioWriter */
    private $ioWriter;

    /**
     * @param array              $config
     * @param SerendipityHQStyle $ioWriter
     */
    public function configure(array $config, SerendipityHQStyle $ioWriter): void
    {
        $this->config   = $config;
        $this->ioWriter = $ioWriter;
    }

    /**
     * @param int $id
     *
     * @return Job|null
     */
    public function findOneById(int $id): ?Job
    {
        $job = $this->findOneBy(['id' => $id]);
        if (null !== $job && ! $job instanceof Job) {
            throw new RuntimeException('The returned result is not null and is not a Job: this is not possible, investigate further.');
        }

        return $job;
    }

    /**
     * Returns a Job that can be run.
     *
     * A Job can be run if it hasn't a startDate in the future and if its parent Jobs are already terminated with
     * success.
     *
     * @param string $queueName
     *
     * @throws StringsException
     * @throws ORMException
     *
     * @return Job|null
     */
    public function findNextRunnableJob(string $queueName): ?Job
    {
        // Collects the Jobs that have to be excluded from the next findNextJob() call
        $excludedJobs = [];

        while (null !== $job = $this->findNextJob($queueName, $excludedJobs)) {
            // If it can be run...
            if ($job->canRun()) {
                // Refresh the Job to get loaded again child and parent Jobs that were eventually detached
                $this->getEntityManager()->refresh($job);

                if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->ioWriter->infoLineNoBg(sprintf('Job <success-nobg>#%s</success-nobg> ready to run.', $job->getId()));
                }

                // ... Return it
                return $job;
            }

            if ($this->ioWriter->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->ioWriter->infoLineNoBg(sprintf('Job <success-nobg>#%s</success-nobg> cannot run because <success-nobg>%s</success-nobg>.', $job->getId(), $job->getCannotRunBecause()));
            }

            // The Job cannot be run
            $excludedJobs[] = $job->getId();

            // Remove it from the Entity Manager to free some memory
            JobsManager::detach($job);
        }

        return null;
    }

    /**
     * @throws NonUniqueResultException
     *
     * @return int
     */
    public function countStaleJobs(): int
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();

        $queryBuilder->select('COUNT(j)')->from(Job::class, 'j')
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
     * @param string                       $queueName
     * @param \DateTime|\DateTimeImmutable $maxRetentionDate
     *
     * @return array
     */
    public function findExpiredJobs(string $queueName, \DateTimeInterface $maxRetentionDate): array
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();

        $queryBuilder->select('j')->from(Job::class, 'j')
                     ->where(
                         $queryBuilder->expr()->orX(
                             $queryBuilder->expr()->eq('j.status', ':succeeded'),
                             $queryBuilder->expr()->eq('j.status', ':retry_succeeded'),
                             $queryBuilder->expr()->eq('j.status', ':aborted'),
                             $queryBuilder->expr()->eq('j.status', ':failed'),
                             $queryBuilder->expr()->eq('j.status', ':retry_failed'),
                             $queryBuilder->expr()->eq('j.status', ':cancelled')
                         ))
                     ->setParameter('succeeded', Job::STATUS_SUCCEEDED)
                     ->setParameter('retry_succeeded', Job::STATUS_RETRY_SUCCEEDED)
                     ->setParameter('aborted', Job::STATUS_ABORTED)
                     ->setParameter('failed', Job::STATUS_FAILED)
                     ->setParameter('retry_failed', Job::STATUS_RETRY_FAILED)
                     ->setParameter('cancelled', Job::STATUS_CANCELLED)
                     ->andWhere($queryBuilder->expr()->eq('j.queue', ':queue_name'))
                     ->setParameter('queue_name', $queueName)
                     ->andWhere($queryBuilder->expr()->lt('j.closedAt', ':max_date'))
                     ->andWhere($queryBuilder->expr()->orX(
                         $queryBuilder->expr()->andX(
                             $queryBuilder->expr()->isNotNull('j.startedAt'),
                             $queryBuilder->expr()->neq('j.status', ':cancelled')
                         ),
                         $queryBuilder->expr()->andX(
                             $queryBuilder->expr()->isNull('j.startedAt'),
                             $queryBuilder->expr()->eq('j.status', ':cancelled')
                         )
                     ))
                     ->setParameter('max_date', $maxRetentionDate, Type::DATETIME)
                     ->orderBy('j.closedAt', 'DESC');

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Checks if the given Job exists or not.
     *
     * @param string|null $command
     * @param array|null  $input
     * @param string|null $queue
     * @param bool        $fullSearch If false, will search only in the not already started jobs
     *
     * @return array|null
     */
    public function findBySearch(?string $command = null, ?array $input = null, ?string $queue = 'default', bool $fullSearch = false): ?array
    {
        if (null === $command && (null === $input || empty($input)) && null === $queue) {
            throw new BadMethodCallException('You should pass at least one between "command", "input" and "queue".');
        }

        $queryBuilder = $this->getEntityManager()->createQueryBuilder();

        $queryBuilder->select('j')->from(Job::class, 'j');

        if (null !== $command) {
            $queryBuilder
                ->where($queryBuilder->expr()->eq('j.command', ':command'))
                ->setParameter('command', $command);
        }

        if (null !== $queue) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('j.queue', ':queue'))->setParameter('queue', $queue);
        }

        if (false === $fullSearch) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull('j.startedAt'));
        }

        if (null !== $input) {
            if (isset($input[self::ARGUMENTS]) && \is_array($input[self::ARGUMENTS])) {
                foreach ($input[self::ARGUMENTS] as $argument) {
                    $queryBuilder->andWhere($queryBuilder->expr()->like('j.input', $queryBuilder->expr()->literal('%' . $argument . '%')));
                }
            }

            if (isset($input[self::OPTIONS]) && \is_array($input[self::OPTIONS])) {
                foreach ($input[self::OPTIONS] as $option => $value) {
                    $queryBuilder->andWhere($queryBuilder->expr()->like('j.input', $queryBuilder->expr()->literal('%' . \serialize([$option => $value]) . '%')));
                }
            }

            if (isset($input[self::SHORTCUTS]) && \is_array($input[self::SHORTCUTS])) {
                foreach ($input[self::SHORTCUTS] as $shortcut => $value) {
                    $queryBuilder->andWhere($queryBuilder->expr()->like('j.input', $queryBuilder->expr()->literal('%' . \serialize([$shortcut => $value]) . '%')));
                }
            }
        }

        return $queryBuilder->getQuery()->execute();
    }

    /**
     * @param array $knownAsStale
     *
     * @throws NonUniqueResultException
     *
     * @return Job|null
     */
    public function findNextStaleJob(array $knownAsStale): ?Job
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();
        $queryBuilder->select('j')->from(Job::class, 'j')
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
     * Finds the next Job to process.
     *
     * @param string $queueName
     * @param array  $excludedJobs The Jobs that have to be excluded from the SELECT
     *
     * @throws NonUniqueResultException
     *
     * @return Job|null
     */
    private function findNextJob(string $queueName, array $excludedJobs = []): ?Job
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();
        $queryBuilder->select('j')->from(Job::class, 'j')
                     ->orderBy('j.priority', self::ASC)
                     ->addOrderBy('j.createdAt', self::ASC)
                     ->addOrderBy('j.id', self::ASC)
            // The status MUST be NEW
                     ->where($queryBuilder->expr()->eq('j.status', ':status'))->setParameter('status', Job::STATUS_NEW)
            // It hasn't an executeAfterTime set or the set time is in the past
                     ->andWhere(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->isNull('j.executeAfterTime'),
                    $queryBuilder->expr()->lt('j.executeAfterTime', ':now')
                )
            )->setParameter('now', new DateTime(), 'datetime')
                     ->andWhere($queryBuilder->expr()->eq('j.queue', ':queue'))->setParameter('queue', $queueName);

        // If there are excluded Jobs...
        if (false === empty($excludedJobs)) {
            // The ID hasn't to be one of them
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('j.id', ':excludedJobs')
            )->setParameter('excludedJobs', $excludedJobs, Connection::PARAM_INT_ARRAY);
        }

        $this->configureQueues($queryBuilder);

        return $queryBuilder->getQuery()->setCacheable(false)->setMaxResults(1)->getOneOrNullResult();
    }

    /**
     * Configures in the query the queues to include.
     *
     * @param QueryBuilder $queryBuilder
     */
    private function configureQueues(QueryBuilder $queryBuilder): void
    {
        // Set the queues to include
        if (isset($this->config['included_queues'])) {
            $queryBuilder->andWhere($queryBuilder->expr()->in('j.queue', ':includedQueues'))
                         ->setParameter('includedQueues', $this->config['included_queues'], Connection::PARAM_STR_ARRAY);
        }
    }
}
