*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this included!*

How to install Serendipity HQ Commands Quesus Bundle
====================================================

Step 1: Download the Bundle
---------------------------

Open a command console, go to your project directory and execute the
following command to download the latest stable version of this bundle:

```console
$ composer require serendipity_hq/commands-queues-bundle
```

This command requires you to have Composer installed globally, as explained in the
 [installation chapter](https://getcomposer.org/doc/00-intro.md) of the Composer documentation.

Step 2: Enable the SHQCommandsQueuesBundle
------------------------------------------

Then, enable the bundle by adding the following line in the `config/bundles.php` file of your project:

```php
<?php

return [
    // ...
    SerendipityHQ\Bundle\CommandsQueuesBundle\SHQCommandsQueuesBundle::class => ['all' => true],
    //...
];
```

Step 3: Update your database schema
-----------------------------------

Last thing you have to do is to update your database schema to make `SHQCommandsQueuesBundle` able to work on your app:

    bin/console doctrine:schema:update --force

If you use [Doctrine Migrations](http://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html):

    bin/console doctrine:migrations:diff &&
    bin/console doctrine:migrations:migrate

*Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container) or run `composer global require symfony/thanks && composer thanks` to say thank you to all libraries you use in your current project, this one too!*

Step 4: Test the bundle
-----------------------

Before you can use the bundle, you need to create also a configuration file for it in `config/packages/shq_commands_queues.yaml`.

The best way to understand how to configure the bundle is to test it.

For this reason we have prepared a fast start tutorial to help you test the bundle with real jobs.

The next chapter is dedicated to the automatic creation of some Jobs that will help you understand the behaviors of the `SHQCommandsQueuesBundle`.

Read the next chapter [Start in less than 5 minutes](20-Fast-Start.md) to create hundreds (or thusands!) of fake jobs to test the bundle: then you will be guided through the steps required to make your app able to use the queues.

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

([Go back to index](00-Index.md)) | Next step: [Start in less than 5 minutes](20-Fast-Start.md)
