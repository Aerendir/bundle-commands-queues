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

use Rector\Config\RectorConfig;
use SerendipityHQ\Integration\Rector\SerendipityHQ;

$toSkip = SerendipityHQ::buildToSkip(SerendipityHQ::SHQ_SYMFONY_BUNDLE_SKIP);

return RectorConfig::configure()
                   ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
                   ->withBootstrapFiles([__DIR__ . '/vendor-bin/phpunit/vendor/autoload.php'])
                   ->withPhpSets(php82: true)
                   ->withSets([
                       Rector\Set\ValueObject\SetList::CODE_QUALITY,
                       Rector\Set\ValueObject\SetList::CODING_STYLE,
                       Rector\Set\ValueObject\SetList::TYPE_DECLARATION,
                   ])
                   ->withImportNames(importNames: true, importDocBlockNames: true, importShortClasses: false)
                   ->withSkip($toSkip)
                   ->withCache(
                       './var/cache/rector',
                       Rector\Caching\ValueObject\Storage\FileCacheStorage::class
                   )
                   ->withComposerBased(doctrine: true, phpunit: true, symfony: true);
