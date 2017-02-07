How to use Serendipity HQ Quesus Bundle
=======================================

To use ShqQueuesBundle you have to do basiclly two things:

1. Start the daemon that listens for new `Job`s to process;
2. Create new `Job`s to process.

## 1. Start the Daemon

To start the daemon you have to simply run the command

    app/console queues:run --env=prod

**Remember to add the argument `--env=prod` if you are working locally! This will prevent the Daemon from running out of
 memory as in development mode Symfony collects a lot of information that grows very fast, consuming all the memory and
 causing a fatal error.**

To see the output of the daemon, add the `-v` argument.

    app/console queues:run --env=prod -v

To run the daemon on production you have to use some sort of process manager, like
 [`supervisord`](http://supervisord.org/).

## 2. How create the `Job`s

To fastly create testing jobs in development, you can go to `http://127.0.0.8:8000/_queues` and then click the link
 "Generate random Jobs.".

This way will be automatically created some `Job`s that will run the command `queues:test`.

In your console, after you have started the daemon, you will see all the Job processing.

The `Job`s you see are created in this way:

    for ($i = 0; $i <= 10; $i++) {
        // First: we create a Job to push to the queue
        $arguments = '--id='.$i;
        $scheduledJob = new Job('queues:test', $arguments);
        $this->get('queues')->schedule($scheduledJob);
    }

So, as you can see, the basic way to create a `Job` in your code is this:

    // Create the Job object
    $scheduledJob = new Job('queues:test', '-v --argument=value');
    
    // Save it to the database using the `queues` service
    $this->get('queues')->schedule($scheduledJob);

**Avoid using your own entity manager to flush the jobs to the database but ever use the provided `queues` service.**
