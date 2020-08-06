<?php
/**
 * This is an automatically generated baseline for Phan issues.
 * When Phan is invoked with --load-baseline=path/to/baseline.php,
 * The pre-existing issues listed in this file won't be emitted.
 *
 * This file can be updated by invoking Phan with --save-baseline=path/to/baseline.php
 * (can be combined with --load-baseline)
 */
return [
    // # Issue statistics:
    // PhanRedefinedClassReference : 510+ occurrences
    // PhanUnreferencedPublicMethod : 40+ occurrences
    // PhanPluginUnreachableCode : 15+ occurrences
    // PhanUnreferencedProtectedProperty : 10+ occurrences
    // PhanReadOnlyPrivateProperty : 9 occurrences
    // PhanTypeArraySuspiciousNullable : 8 occurrences
    // PhanRedefinedExtendedClass : 7 occurrences
    // PhanTypeMismatchArgumentNullable : 5 occurrences
    // PhanUnreferencedClass : 4 occurrences
    // PhanDeprecatedFunction : 2 occurrences
    // PhanReadOnlyPublicProperty : 2 occurrences
    // PhanUnreferencedClosure : 2 occurrences
    // PhanUnusedClosureParameter : 2 occurrences
    // PhanUnusedProtectedFinalMethodParameter : 2 occurrences
    // ConstReferenceClassNotImported : 1 occurrence
    // ConstReferenceConstNotFound : 1 occurrence
    // PhanDeprecatedClassConstant : 1 occurrence
    // PhanTypeInvalidDimOffset : 1 occurrence
    // PhanTypeNoPropertiesForeach : 1 occurrence
    // PhanUndeclaredMethod : 1 occurrence
    // PhanUnreferencedProtectedMethod : 1 occurrence

    // Currently, file_suppressions and directory_suppressions are the only supported suppressions
    'file_suppressions' => [
        'src/Admin/Sonata/Entity/DaemonAdmin.php' => ['PhanUnreferencedProtectedProperty'],
        'src/Admin/Sonata/Entity/JobAdmin.php' => ['PhanUnreferencedProtectedProperty'],
        'src/Admin/Sonata/SHQAnsiTheme.php' => ['PhanUnreferencedClass'],
        'src/Admin/Sonata/Twig/CommandsQueuesExtension.php' => ['PhanUnreferencedClass', 'PhanUnreferencedPublicMethod'],
        'src/Command/AbstractQueuesCommand.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass'],
        'src/Command/InternalMarkAsCancelledCommand.php' => ['PhanReadOnlyPublicProperty', 'PhanRedefinedClassReference'],
        'src/Command/RunCommand.php' => ['PhanDeprecatedFunction', 'PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUnreferencedProtectedProperty'],
        'src/Command/TestFailingJobsCommand.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUnreferencedProtectedProperty', 'PhanUnusedProtectedFinalMethodParameter'],
        'src/Command/TestFakeCommand.php' => ['PhanReadOnlyPublicProperty', 'PhanRedefinedClassReference', 'PhanRedefinedExtendedClass'],
        'src/Command/TestRandomJobsCommand.php' => ['PhanPluginUnreachableCode', 'PhanRedefinedClassReference', 'PhanTypeInvalidDimOffset', 'PhanUnreferencedProtectedProperty'],
        'src/Config/DaemonConfig.php' => ['PhanUnreferencedPublicMethod'],
        'src/DependencyInjection/Configuration.php' => ['PhanTypeNoPropertiesForeach', 'PhanUndeclaredMethod', 'PhanUnreferencedClosure'],
        'src/DependencyInjection/SHQCommandsQueuesExtension.php' => ['PhanUnreferencedClass'],
        'src/Entity/Daemon.php' => ['PhanReadOnlyPrivateProperty', 'PhanRedefinedClassReference', 'PhanUnreferencedPublicMethod'],
        'src/Entity/Job.php' => ['PhanPluginUnreachableCode', 'PhanReadOnlyPrivateProperty', 'PhanRedefinedClassReference', 'PhanTypeArraySuspiciousNullable', 'PhanTypeMismatchArgumentNullable', 'PhanUnreferencedPublicMethod'],
        'src/Repository/DaemonRepository.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUnreferencedPublicMethod'],
        'src/Repository/JobRepository.php' => ['PhanDeprecatedClassConstant', 'PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUnreferencedPublicMethod'],
        'src/SHQCommandsQueuesBundle.php' => ['PhanUnreferencedClass'],
        'src/Service/JobsManager.php' => ['PhanDeprecatedFunction', 'PhanPluginUnreachableCode', 'PhanRedefinedClassReference', 'PhanTypeMismatchArgumentNullable'],
        'src/Service/QueuesDaemon.php' => ['PhanRedefinedClassReference', 'PhanTypeArraySuspiciousNullable', 'PhanUnreferencedProtectedMethod'],
        'src/Service/QueuesManager.php' => ['PhanRedefinedClassReference', 'PhanUnreferencedPublicMethod'],
        'src/Util/InputParser.php' => ['PhanTypeArraySuspiciousNullable'],
        'src/Util/JobsMarker.php' => ['PhanRedefinedClassReference'],
        'src/Util/Profiler.php' => ['ConstReferenceClassNotImported', 'ConstReferenceConstNotFound', 'PhanRedefinedClassReference', 'PhanUnreferencedPublicMethod'],
        'src/Util/ProgressBarFactory.php' => ['PhanRedefinedClassReference', 'PhanUnusedClosureParameter'],
        'tests/Util/InputParserTest.php' => ['PhanRedefinedExtendedClass', 'PhanUnreferencedPublicMethod'],
    ],
    // 'directory_suppressions' => ['src/directory_name' => ['PhanIssueName1', 'PhanIssueName2']] can be manually added if needed.
    // (directory_suppressions will currently be ignored by subsequent calls to --save-baseline, but may be preserved in future Phan releases)
];
