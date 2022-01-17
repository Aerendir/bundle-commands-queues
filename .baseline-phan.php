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
    // PhanRedefinedClassReference : 550+ occurrences
    // PhanUnreferencedPublicMethod : 40+ occurrences
    // PhanDeprecatedFunction : 25+ occurrences
    // PhanPluginUnreachableCode : 15+ occurrences
    // PhanUndeclaredClassMethod : 15+ occurrences
    // PhanUndeclaredMethod : 10+ occurrences
    // PhanReadOnlyPrivateProperty : 9 occurrences
    // PhanRedefinedExtendedClass : 8 occurrences
    // PhanTypeArraySuspiciousNullable : 7 occurrences
    // PhanUndeclaredTypeParameter : 7 occurrences
    // PhanUnreferencedProtectedProperty : 7 occurrences
    // PhanUnreferencedClass : 6 occurrences
    // PhanUnreferencedProtectedMethod : 6 occurrences
    // PhanTypeMismatchArgumentNullable : 5 occurrences
    // PhanUnreferencedClosure : 4 occurrences
    // PhanParamTooMany : 3 occurrences
    // PhanUndeclaredExtendedClass : 3 occurrences
    // PhanUndeclaredTypeThrowsType : 3 occurrences
    // PhanUnusedClosureParameter : 2 occurrences
    // PhanUnusedProtectedFinalMethodParameter : 2 occurrences
    // ConstReferenceClassNotImported : 1 occurrence
    // ConstReferenceConstNotFound : 1 occurrence
    // PhanRedefinedInheritedInterface : 1 occurrence
    // PhanTypeMismatchArgumentReal : 1 occurrence
    // PhanUndeclaredClass : 1 occurrence
    // PhanUndeclaredClassConstant : 1 occurrence
    // PhanUndeclaredClassInstanceof : 1 occurrence
    // PhanUndeclaredClassReference : 1 occurrence
    // PhanUnreferencedPrivateMethod : 1 occurrence

    // Currently, file_suppressions and directory_suppressions are the only supported suppressions
    'file_suppressions' => [
        'src/Admin/Sonata/Entity/DaemonAdmin.php' => ['PhanUndeclaredClassMethod', 'PhanUndeclaredExtendedClass', 'PhanUndeclaredTypeParameter', 'PhanUnreferencedClass', 'PhanUnreferencedProtectedMethod', 'PhanUnreferencedProtectedProperty'],
        'src/Admin/Sonata/Entity/JobAdmin.php' => ['PhanUndeclaredClass', 'PhanUndeclaredClassMethod', 'PhanUndeclaredExtendedClass', 'PhanUndeclaredMethod', 'PhanUndeclaredTypeParameter', 'PhanUnreferencedClass', 'PhanUnreferencedProtectedMethod', 'PhanUnreferencedProtectedProperty'],
        'src/Admin/Sonata/SHQAnsiTheme.php' => ['PhanUnreferencedClass'],
        'src/Admin/Sonata/Twig/CommandsQueuesExtension.php' => ['PhanRedefinedClassReference', 'PhanUndeclaredClassMethod', 'PhanUndeclaredExtendedClass', 'PhanUnreferencedClass', 'PhanUnreferencedClosure', 'PhanUnreferencedPublicMethod'],
        'src/Command/AbstractQueuesCommand.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass'],
        'src/Command/InternalMarkAsCancelledCommand.php' => ['PhanDeprecatedFunction', 'PhanRedefinedClassReference'],
        'src/Command/RunCommand.php' => ['PhanDeprecatedFunction', 'PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUndeclaredTypeThrowsType'],
        'src/Command/TestFailingJobsCommand.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUnusedProtectedFinalMethodParameter'],
        'src/Command/TestFakeCommand.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass'],
        'src/Command/TestRandomJobsCommand.php' => ['PhanDeprecatedFunction', 'PhanPluginUnreachableCode', 'PhanRedefinedClassReference', 'PhanTypeMismatchArgumentReal'],
        'src/Config/DaemonConfig.php' => ['PhanUnreferencedPublicMethod'],
        'src/DependencyInjection/Configuration.php' => ['PhanRedefinedClassReference', 'PhanRedefinedInheritedInterface', 'PhanUndeclaredMethod', 'PhanUnreferencedClosure'],
        'src/DependencyInjection/SHQCommandsQueuesExtension.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUndeclaredClassReference', 'PhanUnreferencedClass'],
        'src/Entity/Daemon.php' => ['PhanReadOnlyPrivateProperty', 'PhanRedefinedClassReference', 'PhanUnreferencedPublicMethod'],
        'src/Entity/Job.php' => ['PhanPluginUnreachableCode', 'PhanReadOnlyPrivateProperty', 'PhanRedefinedClassReference', 'PhanTypeArraySuspiciousNullable', 'PhanTypeMismatchArgumentNullable', 'PhanUnreferencedPublicMethod'],
        'src/Repository/DaemonRepository.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUnreferencedPublicMethod'],
        'src/Repository/JobRepository.php' => ['PhanRedefinedClassReference', 'PhanRedefinedExtendedClass', 'PhanUndeclaredClassConstant', 'PhanUnreferencedPublicMethod'],
        'src/SHQCommandsQueuesBundle.php' => ['PhanRedefinedExtendedClass', 'PhanUnreferencedClass'],
        'src/Service/JobsManager.php' => ['PhanDeprecatedFunction', 'PhanPluginUnreachableCode', 'PhanRedefinedClassReference', 'PhanTypeMismatchArgumentNullable'],
        'src/Service/QueuesDaemon.php' => ['PhanDeprecatedFunction', 'PhanParamTooMany', 'PhanRedefinedClassReference', 'PhanTypeArraySuspiciousNullable', 'PhanUndeclaredTypeThrowsType', 'PhanUnreferencedPrivateMethod'],
        'src/Service/QueuesManager.php' => ['PhanRedefinedClassReference', 'PhanUnreferencedPublicMethod'],
        'src/Util/InputParser.php' => ['PhanTypeArraySuspiciousNullable'],
        'src/Util/JobsMarker.php' => ['PhanDeprecatedFunction', 'PhanRedefinedClassReference', 'PhanUndeclaredClassInstanceof'],
        'src/Util/Profiler.php' => ['ConstReferenceClassNotImported', 'ConstReferenceConstNotFound', 'PhanDeprecatedFunction', 'PhanRedefinedClassReference', 'PhanUnreferencedPublicMethod'],
        'src/Util/ProgressBarFactory.php' => ['PhanRedefinedClassReference', 'PhanUnusedClosureParameter'],
        'tests/Util/InputParserTest.php' => ['PhanUnreferencedPublicMethod'],
    ],
    // 'directory_suppressions' => ['src/directory_name' => ['PhanIssueName1', 'PhanIssueName2']] can be manually added if needed.
    // (directory_suppressions will currently be ignored by subsequent calls to --save-baseline, but may be preserved in future Phan releases)
];
