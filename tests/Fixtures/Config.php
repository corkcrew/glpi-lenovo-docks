<?php

/** SPDX-License-Identifier: MIT */

namespace ItcoreTests;

final class Config
{
    public static array $values = [];

    public static function getConfigurationValues(string $context): array
    {
        return self::$values[$context] ?? [];
    }
}
