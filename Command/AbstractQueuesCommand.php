<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Command;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\JobsMarker;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Formatter\SerendipityHQOutputFormatter;
use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An abstract command to manage common dependencies of all other commands.
 */
abstract class AbstractQueuesCommand extends ContainerAwareCommand
{
    /** @var  EntityManager $entityManager */
    private $entityManager;

    /** @var  SerendipityHQStyle */
    private $ioWriter;

    /** @var  JobsMarker $jobsMarker */
    private $jobsMarker;

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Create the Input/Output writer
        $this->ioWriter = new SerendipityHQStyle($input, $output);
        $this->ioWriter->setFormatter(new SerendipityHQOutputFormatter(true));

        $this->entityManager = $this->getContainer()->get('commands_queues.do_not_use.entity_manager');

        return 0;
    }

    /**
     * @return EntityManager
     */
    protected final function getEntityManager() : EntityManager
    {
        return $this->entityManager;
    }

    /**
     * @return JobsMarker
     */
    protected final function getJobsMarker() : JobsMarker
    {
        if (null === $this->jobsMarker) {
            $this->jobsMarker = $this->getContainer()->get('commands_queues.do_not_use.jobs_marker');
        }

        return $this->jobsMarker;
    }

    /**
     * @return SerendipityHQStyle
     */
    protected final function getIoWriter() : SerendipityHQStyle
    {
        return $this->ioWriter;
    }
}
