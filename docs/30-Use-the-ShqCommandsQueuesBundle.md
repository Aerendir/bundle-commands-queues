*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this included!*

How to use Serendipity HQ Commands Quesus Bundle
================================================

To use `SHQCommandsQueuesBundle` you have to do basically three things:

1. In the file `config/packages/shq_commands_queues.yaml`, define the daemons you want to use and the queues they have to process;
2. Start the daemons using `bin/console queues:run --daemon damon_name`
3. Create new `Job`s to process.

## 1. Create the daemons and the queues

In the previous chapter "[FastStart](20-Fast-Start.md)" we have seen a basic configuration for `SHQCommandsQueuesBundle`:

    # config/packages/shq_commands_queues.yaml
    shq_commands_queues:
        daemon_max_runtime: 100000
        daemons:
            daemon_1:
                queues: ['queue_1', 'queue_2', 'queue_3', 'queue_4', 'queue_5']
        queues:
            queue_1:
                # The number of concurrent jobs to process at the same time.
                queue_max_concurrent_jobs: 3

As you can see, under the node `shq_commands_queues.daemons` you list the daemons: the daemon's node will be the daemon's name.

So, in this configuration, `daemon_1` is the name of a daemon: to start it you can use `bin/consoel queues:run --daemon daemon_1`.

Under the `shq_commands_queues.daemons.damon_name.queues` node in each daemon, you define the queues that it has to process

You don't need to explicitly configure the queues: they are automatically used by the daemon to get all the `Job`s that are in these queues.

If you want to refine the configuration of a queue, you can explicitly list it under the `shq_commands_queues.queues` and there set the configuration you like.

If you completely omit any configuration (for example, you don't create the config file at all), the bundle will create a daemon called `default` that manages
 a queue called `default` (and `default` is the queue set in the `Job`s by default when you don't specify the queue to which they belong to).

## 2. Start the Daemon

To start a daemon you have to simply run the command

    bin/console queues:run

This is the tiniest command you can fire to launch the `SHQCommandsQueuesBundle` daemon.

This command will work will listen for all the existent queues, present and future ones.

But you can also be more precise about what you would listen with your daemons.

If you have only one daemon configured, you can also omit the `--daemon` option: `SHQCommandsQueuesBundle` will automatically start the single daemon configured.

If you have more than one daemon configured, instead, you need to specify the `--daemon` option as the bundle will not be able to decide which one to start on its own.

When starting the daemon is warmly suggested to pass also the option `--env`: passing it will activate the memory management features that will make the daemon free up memory during its execution to avoid memory leaks.

Memory leaks will be anyway possible depending on the commands you launch, but using this option will make you sure the daemons will do all their best to avoid them.

**Remember to add the argument `--env=prod` also if you are working locally! This will prevent the Daemon from running
out of memory as in development mode Symfony collects a lot of information that grows very fast, consuming all the
memory and causing a fatal error.**

To see a more detailed output of the daemon, add the `-v` argument or the `-vv` one.

    bin/console queues:run --env=prod -vv

To run the daemon on production you have to use some sort of process manager, like
 [`supervisord`](http://supervisord.org/).

## 2. Creating the `Job`s: a primer

The basic way to create a `Job` in your code is this:

    // Create the Job object
    $scheduledJob = new Job('queues:test', '-v --argument=value', 'default');

    // Save it to the database using the `queues` service
    $this->get('queues')->schedule($scheduledJob);

The first parameter of the `Job` object is the command.

The second parameter is the attributes and options required by your command.

The third parameter is the queue in which the `Job` will be added.

When you use the queues service, the job is persisted and flushed: the `EntityManager` used by the queues service is different than the one configured in your app, so, on flush, there is no risk to save to the database other
 entities managed by your application's `EntityManager`: the two are completely different and separate from each other.

Try to create a `Job` and see the Daemon processing it!

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

([Go back to index](00-Index.md)) | Next step: [Configuration](40-Configuration.md)
