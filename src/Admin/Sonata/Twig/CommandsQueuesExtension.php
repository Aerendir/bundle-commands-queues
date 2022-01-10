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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Admin\Sonata\Twig;

use Safe\Exceptions\StringsException;
use function Safe\sprintf;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Util\InputParser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * {@inheritdoc}
 */
final class CommandsQueuesExtension extends AbstractExtension
{
    /** @var string */
    private const OPTIONS = 'options';
    /** @var string */
    private const __ID = '--id';
    /** @var UrlGeneratorInterface $generator */
    private $generator;

    /**
     * @param UrlGeneratorInterface $generator
     */
    public function __construct(UrlGeneratorInterface $generator)
    {
        $this->generator = $generator;
    }

    /**
     * {@inheritdoc}
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('commands_queues_get_id_option_value', function (Job $job): ?string {
                return $this->getIdOptionValue($job);
            }),
            new TwigFilter('commands_queues_render_input', function (?array $input): ?string {
                return $this->getRenderedInput($input);
            }),
        ];
    }

    /**
     * @param Job $job
     *
     * @throws StringsException
     *
     * @return string|null
     */
    public function getIdOptionValue(Job $job): ?string
    {
        $input = $job->getInput();
        if (null !== $input && false === \array_key_exists(self::OPTIONS, $input) && isset($input[self::OPTIONS][self::__ID])) {
            $url = $this->generator->generate('admin_serendipityhq_commandsqueues_job_show', ['id' => $input[self::OPTIONS][self::__ID]], UrlGeneratorInterface::ABSOLUTE_PATH);

            return sprintf('<a href="%s">#%s</a>', $url, $input[self::OPTIONS][self::__ID]);
        }

        return null;
    }

    /**
     * @param array|null $input
     *
     * @return string|null
     */
    public function getRenderedInput(?array $input): ?string
    {
        return InputParser::stringify($input);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'commands_queues';
    }
}
