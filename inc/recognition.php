<?php

/** SPDX-License-Identifier: MIT */

function plugin_itcoreinventory_catalog(?string $manufacturer = null): array
{
    return PluginItcoreinventoryProfiles::catalog($manufacturer);
}
function plugin_itcoreinventory_normalize_serial(
    string $raw,
    string $model,
    array $cfg,
    string $override = ''
): array {
    $raw = strtoupper(trim($raw));
    $serial = $raw;
    $part = '';
    $status = preg_match('/^[A-Z0-9]{8}$/D', $raw) ? 'plain' : 'unrecognized';
    if (!$cfg['normalize_serial']) {
        return [
            'serial' => $raw,
            'part' => '',
            'serial_status' => 'disabled'
        ];
    }
    $pattern = $override !== '' ? $override : $cfg['serial_pattern'];
    if (preg_match('~' . str_replace('~', '\\~', $pattern) . '~uD', $raw, $m)) {
        $candidate = $m[$cfg['serial_group']] ?? '';
        $part = $cfg['part_group'] ? ($m[$cfg['part_group']] ?? '')
        : '';
        if ($candidate === '') {
            return [
                'serial' => $raw,
                'part' => '',
                'serial_status' => 'invalid_groups'
            ];
        }
        if (strlen($part) === 4 && strtoupper($part) !== strtoupper($model)) {
            return [
                'serial' => $raw,
                'part' => $part,
                'serial_status' => 'model_mismatch'
            ];
        }
        $serial = $candidate;
        $status = 'normalized';
    }
    return compact('serial', 'part') + ['serial_status' => $status];
}
function plugin_itcoreinventory_info(string $vid, string $pid, string $raw): ?array
{
    return PluginItcoreinventoryProfiles::info($vid, $pid, $raw);
}
