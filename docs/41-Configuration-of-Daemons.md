*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this included!*

### Configuring Daemons

This is a sample configuration:

    shq_commands_queues:
        daemons:
            daemon_1:
                # The Daemon will die after this amount of seconds (Only one day).
                daemon_max_runtime: 86400
                # The number of concurrent jobs to process at the same time.
                queue_max_concurrent_jobs: 2
                # The amount of seconds to sleep when the worker runs out of jobs.
                daemon_sleep_for: 1
                daemon_profiling_info_interval: 31536000000
                queue_running_jobs_check_interval: 1
                queues: ["queue_1", "queue_2"]
            daemon_2:
                queues: ["queues_3", "queues_4"]

So, to launch a daemon that works with `queue_1` and `queue_2` you have to launch this command:

    bin/console queues:run --env=prod -v --daemon=daemon_1

This Daemon will process only `Job`s in `queue_1` and `Job`s in `queue_2`.

If in your configuration ther is only one Daemon configured (for example, you configured only `aemon_1`), then you can
 omit the `--daemon` argument:

    bin/console queues:run --env=prod -v

This will be equal to:

    bin/console queues:run --env=prod -v --daemon=daemon_1

**You can omit to pass the name of the daemon (the first argument) ONLY WHEN ONLY ONE DAEMON IS CONFIGURED!**

**What happens if you don't start a daemon also for the `daemon_2`?**

In this case you will be alerted when you will launch the command and in the web interface too.

Overriding global daemon configuration
--------------------------------------

Each running `daemon` checks at regualar intervals if other running daemons are still alive (still running).

This parameter defines the amount of seconds between one check and the other.

You can override for each spcific daemon the global configurations you set in the root node `shq_commands_queues`.

### `daemon_alive_daemons_check_interval`

Indicates to the running daemons after how many seconds they have to check if other running daemons are still alive (running). Defaults to 3600 seconds.

    shq_commands_queues:
        daemon_alive_daemons_check_interval: 3600 # All daemons will check other daemons each hour
        daemons:
            daemon_1:
                daemon_alive_daemons_check_interval: 7200 # Only daemon_1, instead, will check other daemons each 2 hours
                queues: ["queue_1", "queue_2"]
            daemon_2:
                queues: ["queues_3", "queues_4"]

The configuration of `daemon_alive_daemons_check_interval` for `daemon_1` will override the global configuration.

Set the value of `daemon_alive_daemons_check_interval` to `0` to completely disable the check.

Setting this parameter to 0 is not permitted in the global configuration to avoid accidentally disabling the check at all.

### `daemon_managed_entities_treshold`

Indicates the maximum number of Jobs that a Daemon can keep in the entity manager at any given time.

    shq_commands_queues:
        daemon_managed_entities_treshold: 100 # All daemons will keep in the EntityManager max 100 Job entities at any given time
        daemons:
            daemon_1:
                daemon_managed_entities_treshold: 200 # Only daemon_1, instead, will keep in the EntityManager 200 Jobs entities at any given time
                queues: ["queue_1", "queue_2"]
            daemon_2:
                queues: ["queues_3", "queues_4"]

### `daemon_max_runtime`

Indicates the maximum amount of seconds the daemon will live. Once elapsed, the daemon will die.

    shq_commands_queues:
        daemon_max_runtime: 0 # All daemons will live forever (0 disables the daemon_max_runtime)
        daemons:
            daemon_1:
                daemon_max_runtime: 200 # Only daemon_1, instead, will live for only 200 seconds
                queues: ["queue_1", "queue_2"]
            daemon_2:
                queues: ["queues_3", "queues_4"]

Set the value of `daemon_max_runtime` to `0` to make the daemon run indefinitely until it intercepts a `SIGTERM` signal or crashes.

### `daemon_profiling_info_interval`

Indicates the amount of seconds between each profiling information collection and printing in the console log

    shq_commands_queues:
        daemon_profiling_info_interval: 300 # All daemons will print profiling info each 5 minutes
        daemons:
            daemon_1:
                daemon_profiling_info_interval: 600 # Only daemon_1, instead, will print profiling info each 10 minutes
                queues: ["queue_1", "queue_2"]
            daemon_2:
                queues: ["queues_3", "queues_4"]

### `daemon_print_profiling_info`

Set it to `true` or `false` to enable or disable the printing of profile info in the console.

    shq_commands_queues:
        daemon_print_profiling_info: true # All daemons will print profiling in
        daemons:
            daemon_1:
                daemon_print_profiling_info: false # Only daemon_1, instead, will live for only 200 seconds
                queues: ["queue_1", "queue_2"]
            daemon_2:
                queues: ["queues_3", "queues_4"]

### `daemon_sleep_for`

This parameter indicates to the daemon for how many seconds it has to sleep if no new jobs are found to process.

To avoid a waste of resource, if the daemon doesn't find any new jobs to process, it will idle for the amoun of seconds set in this parameter.

Once they are elapsed, it will try again to load new jobs: if it founds them, it will start to process them; if no new jobs are found, the daemon will sleep for another `daemon_sleep_for` seconds.

Configuring queues managed by the daemon
----------------------------------------

The parameters that change the behaviors of the queues can be set also at daemon's level.

The following are the parameters that impact on the queues behavior and that can be configured at daemon's level:

    shq_commands_queues:
        daemon_alive_daemons_check_interval: 3600 # Check other daemons each hour
        daemons:
            daemon_1:
                ...
                queue_max_concurrent_jobs: 1 # OPTIONAL

You can overwrite thoss parameters on a queue basis.

See the details about this config params in the section [Configure Queues](42-Configuration-of-queues.md).

<hr />
<h3 align="center">
    <b>Do you like this bundle?</b><br />
    <b><a href="#js-repo-pjax-container">LEAVE A &#9733;</a></b>
</h3>
<p align="center">
    or run<br />
    <code>composer global require symfony/thanks && composer thanks</code><br />
    to say thank you to all libraries you use in your current project, this included!
</p>
<hr />
