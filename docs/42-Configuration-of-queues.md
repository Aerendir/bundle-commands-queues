### `max_concurrent_jobs`

The number of concurrent jobs to process at the same time in each queue.

Each daemon can process, in each queue, more than one jobs at the same time.

This param indicates how many of them it has to process concurrently in each queue: the higher this value, the faster is the queue the more memory it will consume.

    shq_commands_queues:
        max_concurrent_jobs: 5 # All queues will process 5 jobs concurrently
        daemons:
            daemon_1:
                queues: ['queue_1', 'queue_2']
            daemon_2:
                max_concurrent_jobs: 3 # Only queues in daemon_2 will process 3 jobs concurrently
                queues: ['queue_3', 'queue_4']
            daemon_3:
                queues: ['queue_5', 'queue_6']
        queues:
            queue_4:
                max_concurrent_jobs: 2 # Only queue_5 will process 2 jobs concurrently

### `max_retention_days`

The number of days after which a Job that cannot be run anymore will be considered expired and will be removed from the database.

    shq_commands_queues:
        max_retention_days: 360 # All queues will maintain expired jobs in the database for 365 days
        daemons:
            daemon_1:
                queues: ['queue_1', 'queue_2']
            daemon_2:
                max_retention_days: 30 # Only queues in daemon_2 maintain expired jobs in the database for 30 days
                queues: ['queue_3', 'queue_4']
            daemon_3:
                queues: ['queue_5', 'queue_6']
        queues:
            queue_4:
                max_retention_days: 1 # Only queue_5 will maintain expired jobs in the database for 1 day

You can set this value to `0` to remove Jobs from the database as soon as possible.

### `retry_stale_jobs`

If true, stale jobs will be retried when the daemon restarts.

    shq_commands_queues:
        retry_stale_jobs: true # All queues will retry stale jobs
        daemons:
            daemon_1:
                queues: ['queue_1', 'queue_2']
            daemon_2:
                retry_stale_jobs: false # Only queues in daemon_2 will not retry stale jobs
                queues: ['queue_3', 'queue_4']
            daemon_3:
                queues: ['queue_5', 'queue_6']
        queues:
            queue_4:
                retry_stale_jobs: true # Only queue_4 will retry stale jobs

### `running_jobs_check_interval`

The number of seconds after which the running jobs have to be checked.

    shq_commands_queues:
        running_jobs_check_interval: 10 # All queues will check running jobs each 10 seconds
        daemons:
            daemon_1:
                queues: ['queue_1', 'queue_2']
            daemon_2:
                running_jobs_check_interval: 30 # Only queues in daemon_2 check running jobs each 30 seconds
                queues: ['queue_3', 'queue_4']
            daemon_3:
                queues: ['queue_5', 'queue_6']
        queues:
            queue_4:
                running_jobs_check_interval: 150 # Only queue_4 will check running jobs each 150 seconds
