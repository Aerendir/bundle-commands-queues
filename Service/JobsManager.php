<?php

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Service;

use SerendipityHQ\Bundle\ConsoleStyles\Console\Style\SerendipityHQStyle;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
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

    /** @var string $kernelRootDir */
    private $kernelRootDir;

    /** @var string $verbosity */
    private $verbosity;

    /**
     * @param string $kernelRootDir
     */
    public function __construct(string $kernelRootDir)
    {
        $this->kernelRootDir = $kernelRootDir;
    }

    /**
     * @param SerendipityHQStyle $ioWriter
     */
    public function initialize(SerendipityHQStyle $ioWriter)
    {
        $this->env = $ioWriter->getInput()->getOption('env');
        $this->verbosity = $ioWriter->getVerbosity();
    }

    /**
     * @param Process $process
     *
     * @return array
     */
    public function buildDefaultInfo(Process $process)
    {
        return [
            'output'    => $process->getOutput().$process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'debug'     => [
                'exit_code_text'                  => $process->getExitCodeText(),
                'complete_command'                => $process->getCommandLine(),
                'input'                           => $process->getInput(),
                'options'                         => $process->getOptions(),
                'env'                             => $process->getEnv(),
                'working_directory'               => $process->getWorkingDirectory(),
                'enhanced_sigchild_compatibility' => $process->getEnhanceSigchildCompatibility(),
                'enhanced_windows_compatibility'  => $process->getEnhanceWindowsCompatibility(),
            ],
        ];
    }

    /**
     * @param Job $job
     *
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
        $arguments[] = '--env='.$this->env;

        // Verbosity level (only if not normal = agument verbosity not set in command)
        if (OutputInterface::VERBOSITY_NORMAL !== $this->verbosity) {
            $arguments[] = $this->guessVerbosityLevel();
        }

        // The arguments of the command
        $arguments = array_merge($arguments, $job->getArguments());

        // Build the command to be run
        $processBuilder->setArguments($arguments);

        return $processBuilder->getProcess();
    }

    /**
     * Finds the path to the console file.
     *
     * @throws \RuntimeException If the console file cannot be found.
     *
     * @return string
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
                return;
        }
    }
}
