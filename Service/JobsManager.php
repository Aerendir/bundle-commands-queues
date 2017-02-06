<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Service;

use Doctrine\ORM\EntityManager;
use SerendipityHQ\Bundle\QueuesBundle\Model\Job;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Manages the Jobs.
 */
class JobsManager
{
    /** @var string $env */
    private $env;

    /** @var  string $kernelRootDir */
    private $kernelRootDir;

    /** @var  string $verbosity */
    private $verbosity;

    /** @var  EntityManager $entityManager */
    private $entityManager;

    /**
     * @param string $kernelRootDir
     * @param EntityManager $entityManager
     */
    public function __construct(string $kernelRootDir, EntityManager $entityManager)
    {
        $this->kernelRootDir = $kernelRootDir;
        $this->entityManager = $entityManager;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->env = $input->getOption('env');
        $this->verbosity = $output->getVerbosity();
    }

    /**
     * @param Process $process
     * @return array
     */
    public function buildDefaultInfo(Process $process)
    {
        return [
            'output' => $process->getOutput() . $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'debug' => [
                'exit_code_text' => $process->getExitCodeText(),
                'complete_command' => $process->getCommandLine(),
                'input' => $process->getInput(),
                'options' => $process->getOptions(),
                'env' => $process->getEnv(),
                'working_directory' => $process->getWorkingDirectory(),
                'enhanced_sigchild_compatibility' => $process->getEnhanceSigchildCompatibility(),
                'enhanced_windows_compatibility' => $process->getEnhanceWindowsCompatibility()
            ]
        ];
    }

    /**
     * @param Job $job
     * @return \Symfony\Component\Process\Process
     */
    public function createJobProcess(Job $job)
    {
        $processBuilder = new ProcessBuilder();
        $arguments = [];

        // Prepend php
        $arguments[] = 'php';

        // Add the console
        $arguments[] = $this->findConsole();

        // The command to execute
        $arguments[] = $job->getCommand();

        // Environment to use
        $arguments[] = '--env=' . $this->env;

        // Verbosity level
        $arguments[] = $this->guessVerbosityLevel();

        // The arguments of the command
        $arguments = array_merge($arguments, $job->getArguments());

        // Build the command to be run
        $processBuilder->setArguments($arguments);

        return $processBuilder->getProcess();
    }

    /**
     * Finds the path to the console file.
     *
     * @return string
     * @throws \RuntimeException If the console file cannot be found.
     */
    private function findConsole() : string
    {
        if (file_exists($this->kernelRootDir.'/console')) {
            return $this->kernelRootDir.'/console';
        }

        if (file_exists($this->kernelRootDir.'/../bin/console')) {
            return $this->kernelRootDir.'/../bin/console';
        }

        throw new \RuntimeException('Unable to find the console file. You should check your Symfony installation. The console file should be in /app/ folder or in /bin/ folder.');
    }

    /**
     * @return string
     */
    private function guessVerbosityLevel() : string
    {
        switch ($this->verbosity) {
            case OutputInterface::VERBOSITY_QUIET:
                return '-q';
                break;
            case OutputInterface::VERBOSITY_VERBOSE:
                return '-vv';
                break;
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
                return '-vv';
                break;
            case OutputInterface::VERBOSITY_DEBUG:
                return '-vvv';
                break;
            case OutputInterface::VERBOSITY_NORMAL:
            default:
                // This WILL NEVER be reached as default
                return '';
        }
    }
}
