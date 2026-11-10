<?php

/** SPDX-License-Identifier: MIT */

use function ItcoreTests\check as itcoreCheck;

require __DIR__ . '/Assertions.php';
require __DIR__ . '/Fixtures/Config.php';
class_alias(\ItcoreTests\Config::class, 'Config');
require dirname(__DIR__) . '/inc/settings-ui.php';
require dirname(__DIR__) . '/inc/legacy-settings.php';
require dirname(__DIR__) . '/inc/profiles.class.php';
require dirname(__DIR__) . '/inc/settings.php';
require dirname(__DIR__) . '/inc/recognition.php';

$cases = 0;
foreach (plugin_itcoreinventory_catalog() as $model) {
    $pid = $model['pid'];
    $samples = [
        '1SSD21B41469ABCDEFG1',
        '1S' . $model['model'] . 'ABCDEFG1',
        'ABCDEFG1'
    ];
    foreach ($samples as $sample) {
        $info = plugin_itcoreinventory_info('17EF', (string) $pid, $sample);
        itcoreCheck(
            $info !== null && $info['serial'] === 'ABCDEFG1',
            'PID ' . $pid . ' / ' . $sample
        );
        $cases++;
    }
    $wrong = '1S' . ($model['model'] === '40B0' ? '40AY' : '40B0')
    . 'ABCDEFG1';
    $info = plugin_itcoreinventory_info('17EF', (string) $pid, $wrong);
    itcoreCheck(
        $info['serial'] === $wrong && $info['serial_status'] === 'model_mismatch',
        'model mismatch ' . $pid
    );
}
itcoreCheck(
    plugin_itcoreinventory_info('1234', '30B5', '1S40B0ABCDEFG1') === null,
    'unknown vendor'
);
itcoreCheck(
    plugin_itcoreinventory_info('17EF', 'FFFF', '1S40B0ABCDEFG1') === null,
    'unknown PID'
);
itcoreCheck(
    plugin_itcoreinventory_info('17EF', '30B5', '') === null,
    'empty serial'
);
$unknown = plugin_itcoreinventory_info('17EF', '30B5', 'UNFAMILIAR-SERIAL');
itcoreCheck(
    $unknown['serial'] === 'UNFAMILIAR-SERIAL',
    'unknown format preserved'
);
itcoreCheck(
    $unknown['serial_status'] === 'unrecognized',
    'unknown format flagged'
);
$settings = plugin_itcoreinventory_legacy_defaults();
$custom = plugin_itcoreinventory_normalize_serial('X-ABCD1234', '40B0', $settings, '^(X-)([A-Z0-9]{8})$');
itcoreCheck($custom['serial'] === 'ABCD1234', 'model-specific regex');
$settings['enabled'] = false;
PluginItcoreinventorySettings::$overrides['itcoreinventory'] = $settings;
itcoreCheck(
    plugin_itcoreinventory_info('17EF', '30B5', '1S40B0ABCDEFG1') === null,
    'disabled recognition'
);
echo 'PASSED: ' . $cases . ' PID/format cases and safety checks' . PHP_EOL;
