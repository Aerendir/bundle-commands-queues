<p align="center">
    <a href="http://www.serendipityhq.com" target="_blank">
        <img style="max-width: 350px" src="http://www.serendipityhq.com/assets/open-source-projects/Logo-SerendipityHQ-Icon-Text-Purple.png">
    </a>
</p>

<h1 align="center">Serendipity HQ Commands Queues Bundle</h1>
<p align="center">Manages queues and processes jobs in your Symfony App better than simple cronjobs.</p>
<p align="center">
    <a href="https://github.com/Aerendir/bundle-commands-queues/releases"><img src="https://img.shields.io/packagist/v/serendipity_hq/bundle-commands-queues.svg?style=flat-square"></a>
    <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square"></a>
    <a href="https://github.com/Aerendir/bundle-commands-queues/releases"><img src="https://img.shields.io/packagist/php-v/serendipity_hq/bundle-commands-queues?color=%238892BF&style=flat-square&logo=php" /></a>
    <a title="Tested with Symfony ^3.4" href="https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev"><img title="Tested with Symfony ^3.4" src="https://img.shields.io/badge/Symfony-%5E3.4-333?style=flat-square&logo=symfony" /></a>
    <a title="Tested with Symfony ^4.4" href="https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev"><img title="Tested with Symfony ^4.4" src="https://img.shields.io/badge/Symfony-%5E4.4-333?style=flat-square&logo=symfony" /></a>
    <a title="Tested with Symfony ^5.0" href="https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev"><img title="Tested with Symfony ^5.0" src="https://img.shields.io/badge/Symfony-%5E5.0-333?style=flat-square&logo=symfony" /></a>
</p>
<p align="center">
    <a href="https://www.php.net/manual/en/book.json.php"><img src="https://img.shields.io/badge/Requires-ext--json-%238892BF?style=flat-square&logo=php"></a>
    <a href="https://www.php.net/manual/en/book.pcntl.php"><img src="https://img.shields.io/badge/Requires-ext--pcntl-%238892BF?style=flat-square&logo=php"></a>
    <a href="https://sonata-project.org/bundles/admin/master/doc/index.html"><img src="https://img.shields.io/badge/Suggests-sonata--project/admin--bundle-%238892BF?style=flat-square&logo=php"></a>
</p>

## Current Status

[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=Aerendir_bundle-commands-queues&metric=coverage)](https://sonarcloud.io/dashboard?id=Aerendir_bundle-commands-queues)
[![Maintainability Rating](https://sonarcloud.io/api/project_badges/measure?project=Aerendir_bundle-commands-queues&metric=sqale_rating)](https://sonarcloud.io/dashboard?id=Aerendir_bundle-commands-queues)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=Aerendir_bundle-commands-queues&metric=alert_status)](https://sonarcloud.io/dashboard?id=Aerendir_bundle-commands-queues)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=Aerendir_bundle-commands-queues&metric=reliability_rating)](https://sonarcloud.io/dashboard?id=Aerendir_bundle-commands-queues)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=Aerendir_bundle-commands-queues&metric=security_rating)](https://sonarcloud.io/dashboard?id=Aerendir_bundle-commands-queues)
[![Technical Debt](https://sonarcloud.io/api/project_badges/measure?project=Aerendir_bundle-commands-queues&metric=sqale_index)](https://sonarcloud.io/dashboard?id=Aerendir_bundle-commands-queues)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=Aerendir_bundle-commands-queues&metric=vulnerabilities)](https://sonarcloud.io/dashboard?id=Aerendir_bundle-commands-queues)

[![Phan](https://github.com/Aerendir/bundle-commands-queues/workflows/Phan/badge.svg)](https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev)
[![PHPStan](https://github.com/Aerendir/bundle-commands-queues/workflows/PHPStan/badge.svg)](https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev)
[![PSalm](https://github.com/Aerendir/bundle-commands-queues/workflows/PSalm/badge.svg)](https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev)
[![PHPUnit](https://github.com/Aerendir/bundle-commands-queues/workflows/PHPunit/badge.svg)](https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev)
[![Composer](https://github.com/Aerendir/bundle-commands-queues/workflows/Composer/badge.svg)](https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev)
[![PHP CS Fixer](https://github.com/Aerendir/bundle-commands-queues/workflows/PHP%20CS%20Fixer/badge.svg)](https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev)
[![Rector](https://github.com/Aerendir/bundle-commands-queues/workflows/Rector/badge.svg)](https://github.com/Aerendir/bundle-commands-queues/actions?query=branch%3Adev)

## Features

It is possible to run a single job or start a daemon that listens for new jobs and processes them as they are pushed into the queue.

It automatically integrates with SonataAdminBundle for an easier administration.

Do you like this bundle? [**Leave a &#9733;**](#js-repo-pjax-container)!

# Documentation

You can read how to install, configure, test and use the SerendipityHQ Queues Bundle in the [documentation](docs/00-Index.md).
