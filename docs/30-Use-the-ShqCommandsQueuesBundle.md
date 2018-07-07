*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

How to use Serendipity HQ Commands Quesus Bundle
================================================

BEFORE STARTING: A good reading is [Understanding PHP memory](http://www.slideshare.net/jpauli/understanding-php-memory/).

To use `SHQCommandsQueuesBundle` you have to do basically two things:

1. Start the daemon that listens for new `Job`s to process;
2. Create new `Job`s to process.

## 1. Start the Daemon

To start the daemon you have to simply run the command

    app/console queues:run --env=prod

**Remember to add the argument `--env=prod` also if you are working locally! This will prevent the Daemon from running
out of memory as in development mode Symfony collects a lot of information that grows very fast, consuming all the
memory and causing a fatal error.**

To see a more detailed output of the daemon, add the `-v` argument or the `-vv` one.

    app/console queues:run --env=prod

To run the daemon on production you have to use some sort of process manager, like
 [`supervisord`](http://supervisord.org/).

This is the tiniest command you can fire to launch the Queues Daemon.

This will listen for all the existent queues, present and future ones.

But you can also be more precise about what you would listen with your daemons.

### Configuring Daemons

This is a sample configuration:

    shq_commands_queues:
        daemons:
            daemon_1:
                # The Daemon will die after this amount of seconds (Only one day).
                max_runtime: 86400
                # The number of concurrent jobs to process at the same time.
                max_concurrent_jobs: 2
                # The amount of seconds to sleep when the worker runs out of jobs.
                idle_time: 1
                profiling_info_interval: 31536000000
                running_jobs_check_interval: 1
                queues: ["queue_1", "queue_2"]
            daemon_2:
                queues: ["queues_3", "queues_4"]

So, to launch a daemon that works with `queue_1` and `queue_2` you have to launch this command:

    app/console queues:run --env=prod -v --daemon=daemon_1

This Daemon will process only `Job`s in `queue_1` and `Job`s in `queue_2`.

If in your configuration ther is only one Daemon configured (for example, you configured only `aemon_1`), then you can
 omit the `--daemon` argument:

    app/console queues:run --env=prod -v

This will be equal to:

    app/console queues:run --env=prod -v --daemon=daemon_1

**You can omit the `--daemon` argument ONLY WHEN ONLY OONE DAEMON IS CONFIGURED!**

**What happens if you don't start a daemon also for the `daemon_2`?**

In this case you will be alerted when you will launch the command and in the web interface too.

## 2. How to create the `Job`s

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

## 3. How to execute a `Job` after a defined `datetime`

It is possible to set the `Job`  to be executed after a given period of time.

So, for example, you want to create the `Job` today, but you want it will be executed tomorrow at the same time.

It is really easy to do this:

    $job = new Job('queues:test', $arguments = '--id=the_job_id');
    
    // Get the NOW time
    $tomorrow = new \DateTime();
    $tomorrow->modify('+1 days');
    
    // Set the Job to be executed tomorrow
    $job->setExecuteAfterTime($tomorrow);
    
    // Save it to the database using the `queues` service
    $this->get('queues')->schedule($scheduledJob);

This setting doesn't guarantee that the `Job` will be executed exactly at the specified time BUT only that it will be
 considered as processable ONLY after the set `datetime`.

## 4. How to make the executed commands aware of the executing Job

There are times where you want to know from inside your command which Job executed it.

In these situations you can make your Command aware of the calling Job.

To make your command aware of the Job that called it you have to do two things:
 
 1. Call the `Job::makeAwareOfJob` method;
 2. Make your command ready to get the Job id.

### 4.1 Calling `Job::makeAwareOfJob`

This is as simple as

    $job = new Job('queues:test', $arguments = '--id=the_job_id');
    
    // Set the Job to be executed tomorrow
    $job->makeAwareOfJob();

At this point your Job knows that when it will initialize the Command Process, it has to pass its own ID as argument.

And this leads us to the second setp...

### 4.2 Making your command aware of the Job

To make your command aware of the Job, the `JobsManager` does a really simple thing:

it passes the Job's ID as an argument to your command.

So, conversely, your command has to be ready to get it.

to do this you have to add an argument to your command:

    class YourCommand extends ContainerAwareCommand
    {
        /**
         * {@inheritdoc}
         */
        protected function configure()
        {
            $this
                ->setName('my:awesome:command')
                ->setDescription('This command is used to launch the skyrocket that will go on Mars.')
                
                /** Add this Option! **/
                ->addOption('job-id', null, InputOption::VALUE_REQUIRED, 'The ID of the processing Job.');
        }
    
        /**
         * @param InputInterface  $input
         * @param OutputInterface $output
         *
         * @return bool
         */
        protected function execute(InputInterface $input, OutputInterface $output)
        {
            ...
            
            /** Here you can get the processing Job ID **/
            $jobId = $input->getOption('job-id');
            
            ...
        }

Done: now your commands are aware of the Job that executes it!

## Detecting memory leaks

- https://www.google.it/search?q=php+memory+profiling+tools
- http://stackoverflow.com/questions/880458/php-memory-profiling

*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

([Go back to index](00-Index.md)) | Next step: [Configuration](40-Configuration.md)
