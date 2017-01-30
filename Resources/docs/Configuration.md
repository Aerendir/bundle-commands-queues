How to configure Serendipity HQ Stripe Bundle
=============================================

Step 1: Update your database schema
-----------------------------------

First of all, tells Doctrine to look for the QueuesBundle entities when builds mappings.

In your `config.yml`:

```
doctrine:
    orm:
        mappings:
            QueuesBundle: ~
```

Now you can run:

```
$ php app/console doctrine:schema:update --force
```

([Go back to index](Index.md))
