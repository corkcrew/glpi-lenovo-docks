<?php

/** SPDX-License-Identifier: MIT */

namespace ItcoreTests;

use RuntimeException;

function check(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $name);
    }
    echo 'OK ' . $name . PHP_EOL;
}
