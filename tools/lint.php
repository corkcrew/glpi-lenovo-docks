<?php

/** SPDX-License-Identifier: MIT */

$root = dirname(__DIR__);
$failed = false;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    $relative = substr($path, strlen($root) + 1);
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (preg_match('~^(vendor|dist|\.tools|\.git)[/\\\\]~', $relative)) {
        continue;
    }
    passthru(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path),
        $status
    );
    $failed = $failed || $status !== 0;
}
exit($failed ? 1 : 0);
