<?php

/** SPDX-License-Identifier: MIT */

require __DIR__ . '/unit.php';

use GlpiPlugin\Itcoreinventory\PluginItcoreinventoryProfiles as Profiles;
use function ItcoreTests\check as itcoreCheck;

$profileSettings = static function (array $settings): void {
    PluginItcoreinventorySettings::$overrides['itcoreinventory'] = Profiles::validate($settings);
};

$defaults = Profiles::defaults();
itcoreCheck(count($defaults['manufacturers']) === 1, 'only Lenovo shipped');
$profileSettings($defaults);
itcoreCheck(Profiles::info('17EF', '30A9', '1SSD21B41469ZVQ23EBJ')['serial'] === 'ZVQ23EBJ', 'long serial');
$other = [
    'id' => 'test-brand', 'name' => 'Test brand', 'vids' => ['1234', '5678'], 'enabled' => true,
    'models' => [['id' => 'test-model', 'code' => 'TB1', 'name' => 'Test dock', 'enabled' => true, 'pids' => ['ABCD']]],
    'rules' => [[
        'id' => 'extract', 'name' => 'Extract', 'enabled' => true, 'models' => ['test-model'], 'pids' => ['ABCD'],
        'action' => 'extract', 'pattern' => '^TEST-([A-Z0-9]+)$', 'serial_group' => 1,
        'extra' => 'none', 'extra_group' => 0, 'check_model' => false,
    ]],
];
$settings = $defaults;
$settings['manufacturers'][] = $other;
$profileSettings($settings);
foreach (['1234', '5678'] as $vid) {
    $result = Profiles::info($vid, 'ABCD', 'TEST-ZVQ23EBJ');
    itcoreCheck($result['serial'] === 'ZVQ23EBJ' && $result['manufacturer_key'] === 'test-brand', 'multiple VID');
}
$settings['manufacturers'][1]['rules'][0]['enabled'] = false;
$profileSettings($settings);
itcoreCheck(Profiles::info('1234', 'ABCD', 'TEST-ZVQ23EBJ')['serial'] === 'TEST-ZVQ23EBJ', 'disabled rule');
$settings['manufacturers'][1]['rules'][0]['enabled'] = true;
array_unshift($settings['manufacturers'][1]['rules'], [
    'id' => 'keep', 'name' => 'Keep', 'enabled' => true, 'models' => [], 'pids' => [],
    'action' => 'keep', 'pattern' => '', 'serial_group' => 1, 'extra' => 'none', 'extra_group' => 0,
    'check_model' => false,
]);
$profileSettings($settings);
itcoreCheck(Profiles::info('1234', 'ABCD', 'TEST-ZVQ23EBJ')['serial'] === 'TEST-ZVQ23EBJ', 'first matching rule');
itcoreCheck(Profiles::info('1234', 'ABCD', 'TEST-ZVQ23EBJ', 'extract')['serial'] === 'ZVQ23EBJ', 'forced rule');
$export = json_encode(Profiles::export($settings), JSON_THROW_ON_ERROR);
$import = Profiles::import($export, $defaults);
itcoreCheck(count($import['settings']['manufacturers']) === 2, 'import adds manufacturer');
itcoreCheck(!str_contains($export, 'assets_id') && !str_contains($export, 'users_id'), 'portable preset');
$replace = $other;
$replace['id'] = 'different-id';
$envelope = json_encode([
    'format' => Profiles::FORMAT, 'version' => 1,
    'settings' => new stdClass(), 'manufacturers' => [$replace],
]);
$merged = Profiles::import($envelope, $settings);
itcoreCheck($merged['settings']['manufacturers'][1]['id'] === 'test-brand', 'replacement keeps namespace');
$legacy = plugin_itcoreinventory_legacy_defaults();
$legacy['catalog'][0]['enabled'] = false;
$upgraded = Profiles::validate(Profiles::upgrade($legacy));
$profileSettings($upgraded);
itcoreCheck(Profiles::info('17EF', $legacy['catalog'][0]['pid'], 'ABCDEFG1') === null, 'disabled old PID retained');
itcoreCheck(Profiles::info('17EF', '30A9', str_repeat('A', 256)) === null, 'serial length bounded');
$empty = $defaults;
$empty['manufacturers'] = [];
$profileSettings($empty);
itcoreCheck(Profiles::catalog() === [], 'delete all profiles leaves assets untouched');
foreach (['invalid-json', str_repeat('a', 300001), '{"format":"wrong","version":1}'] as $bad) {
    $rejected = false;
    try {
        Profiles::import($bad, $defaults);
    } catch (Throwable) {
        $rejected = true;
    }
    itcoreCheck($rejected, 'invalid import rejected');
}
unset(PluginItcoreinventorySettings::$overrides['itcoreinventory']);
echo "PASSED: profile migration, multiple manufacturers, ordered rules and portable presets\n";
