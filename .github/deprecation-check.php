<?php
/**
 * Load every trait in src/Concerns with all diagnostics enabled, and fail on
 * anything PHP has to say about them.
 *
 * Only traits are checked. The rest of src/ extends XenForo classes, and
 * XenForo is licensed and not available to CI; a trait has no parent class, so
 * it compiles without a forum. The traits are most of the public API, and a
 * deprecation raised in one - an implicitly nullable parameter, for example -
 * would surface in every consuming add-on's test run.
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
