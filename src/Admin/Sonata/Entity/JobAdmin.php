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

namespace SerendipityHQ\Bundle\CommandsQueuesBundle\Admin\Sonata\Entity;

use Knp\Menu\ItemInterface;
use SerendipityHQ\Bundle\CommandsQueuesBundle\Entity\Job;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\DoctrineORMAdminBundle\Filter\DateRangeFilter;

/**
 * @extends AbstractAdmin<Job>
 */
final class JobAdmin extends AbstractAdmin
{
    /** @var string */
    private const URI = 'uri';

    /** @var array<string, string> */
    private const STATUS_TABS = [
        'tab_status_new'             => Job::STATUS_NEW,
        'tab_status_pending'         => Job::STATUS_PENDING,
        'tab_status_running'         => Job::STATUS_RUNNING,
        'tab_status_succeeded'       => Job::STATUS_SUCCEEDED,
        'tab_status_failed'          => Job::STATUS_FAILED,
        'tab_status_aborted'         => Job::STATUS_ABORTED,
        'tab_status_cancelled'       => Job::STATUS_CANCELLED,
        'tab_status_retried'         => Job::STATUS_RETRIED,
        'tab_status_retry_succeeded' => Job::STATUS_RETRY_SUCCEEDED,
        'tab_status_retry_failed'    => Job::STATUS_RETRY_FAILED,
    ];

    /**
     * @return array<int>
     */
    public function getPerPageOptions(): array
    {
        return [50, 100, 1000, 10000];
    }

    protected function generateBaseRoutePattern(bool $isChildAdmin = false): string
    {
        return 'jobs';
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues[DatagridInterface::PAGE]       = 1;
        $sortValues[DatagridInterface::PER_PAGE]   = 50;
        $sortValues[DatagridInterface::SORT_ORDER] = 'DESC';
        $sortValues[DatagridInterface::SORT_BY]    = 'id';
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('command')
            ->add('dependencies');
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('status')
            ->add('command')
            ->add('input')
            ->add('queue')
            ->add('exitCode')
            ->add('priority')
            ->add('createdAt', DateRangeFilter::class)
            ->add('startedAt', DateRangeFilter::class)
            ->add('closedAt', DateRangeFilter::class)
            ->add('executeAfterTime', DateRangeFilter::class);
    }

    protected function configureTabMenu(ItemInterface $menu, string $action, ?AdminInterface $childAdmin = null): void
    {
        $admin = $this->isChild() ? $this->getParent() : $this;

        foreach (self::STATUS_TABS as $tab => $status) {
            $menu->addChild($tab, [
                self::URI => $admin->generateUrl('list', ['filter[status][value]' => $status]),
            ]);
        }

        parent::configureTabMenu($menu, $action, $childAdmin);
    }

    protected function configureRoutes(RouteCollectionInterface $collection): void
    {
        $collection
            ->remove('create')
            ->remove('batch')
            ->remove('delete')
            ->remove('edit')
            ->remove('export');
    }
}
