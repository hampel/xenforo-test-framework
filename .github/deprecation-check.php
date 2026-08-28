<?php
/**
 * Load every trait in src/Concerns with all diagnostics enabled, and fail on
 * anything PHP has to say about them.
 *
 * WHY THIS EXISTS, AND WHY IT ONLY LOOKS AT TRAITS
 *
 * This package cannot be tested in public CI. Its test suite is a scaffold that
 * consuming add-ons copy, and it needs a XenForo install to boot; XF is
 * licensed, absent from Packagist, and has no public stub package. PHPStan is
 * blocked for the same reason - see CLAUDE.md.
 *
 * The traits in src/Concerns are the exception. A trait declares standalone: it
 * has no parent class to resolve, so PHP will compile all eighteen of them with
 * no forum present. The ten classes in src/ extend XF and cannot be loaded here.
 *
 * That is a partial net, and worth having anyway - it covers most of the public
 * API surface, and it catches the failure this was written for. Version 3.0.3
 * shipped thirteen implicitly-nullable parameters that emitted deprecations
 * inside every consuming add-on's test run on PHP 8.4, and failed the suite
 * outright for anyone who had enabled failOnDeprecation. Nothing was watching.
 * Now something is.
 */

$found = [];

set_error_handler(function (int $no, string $str, string $file, int $line) use (&$found): bool {
    $found[] = sprintf('%s:%d  %s', basename($file), $line, $str);
    return true;
});

$traits = glob(__DIR__ . '/../src/Concerns/*.php');
foreach ($traits as $file) {
    include_once $file;
}

restore_error_handler();

if ($found) {
    fwrite(STDERR, sprintf("%d diagnostic(s) on PHP %s:\n\n", count($found), PHP_VERSION));
    fwrite(STDERR, implode("\n", $found) . "\n");
    exit(1);
}

printf("%d traits loaded clean on PHP %s\n", count($traits), PHP_VERSION);
