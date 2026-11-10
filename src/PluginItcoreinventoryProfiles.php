<?php

/** SPDX-License-Identifier: MIT */

namespace GlpiPlugin\Itcoreinventory;

use Config;
use RuntimeException;

/** Portable manufacturer profiles and deterministic, bounded serial processing. */
final class PluginItcoreinventoryProfiles
{
    public const FORMAT = 'glpi-dock-preset';
    public const GLOBALS = ['enabled', 'migrate_existing', 'add_marker', 'dock_location', 'type_name'];

    public static function defaults(): array
    {
        return self::upgrade(\plugin_itcoreinventory_legacy_defaults());
    }

    public static function read(): array
    {
        if (isset(PluginItcoreinventorySettings::$overrides['itcoreinventory'])) {
            return self::upgrade(PluginItcoreinventorySettings::$overrides['itcoreinventory']);
        }
        $config = Config::getConfigurationValues('plugin:itcoreinventory');
        $saved = json_decode($config['settings'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        return self::upgrade(is_array($saved) && $saved ? $saved : \plugin_itcoreinventory_legacy_defaults());
    }

    public static function upgrade(array $settings): array
    {
        if (($settings['schema_version'] ?? null) === 2) {
            return $settings;
        }
        $legacy = array_replace(\plugin_itcoreinventory_legacy_defaults(), $settings);
        $models = [];
        $overrides = [];
        foreach ($legacy['catalog'] as $row) {
            $code = strtoupper($row['model']);
            $id = 'model-' . strtolower($code);
            if (!isset($models[$id])) {
                $models[$id] = [
                    'id' => $id, 'code' => $code, 'name' => $row['name'],
                    'enabled' => true, 'pids' => [], 'disabled_pids' => [],
                ];
            }
            if (!$row['enabled']) {
                $models[$id]['pids'][] = strtoupper((string) $row['pid']);
                $models[$id]['disabled_pids'][] = strtoupper((string) $row['pid']);
                continue;
            }
            $models[$id]['pids'][] = strtoupper((string) $row['pid']);
            if (!empty($row['serial_pattern'])) {
                $overrides[] = self::legacyRule($legacy, $row['serial_pattern'], 'pid-' . $row['pid'])
                    + ['models' => [$id], 'pids' => [strtoupper((string) $row['pid'])]];
            }
        }
        foreach ($models as &$model) {
            $model['enabled'] = (bool) array_diff($model['pids'], $model['disabled_pids']);
        }
        unset($model);
        $rule = self::legacyRule($legacy, $legacy['serial_pattern'], 'default');
        $rules = $legacy['normalize_serial'] ? [...$overrides, $rule + ['models' => [], 'pids' => []]] : [];
        return array_intersect_key($legacy, array_flip(self::GLOBALS)) + [
            'schema_version' => 2,
            'manufacturers' => [[
                'id' => 'lenovo', 'name' => $legacy['manufacturer'], 'vids' => [strtoupper($legacy['vendor_id'])],
                'enabled' => true, 'models' => array_values($models), 'rules' => $rules,
            ]],
        ];
    }

    private static function legacyRule(array $old, string $pattern, string $id): array
    {
        return [
            'id' => $id, 'name' => $id === 'default' ? 'Составной номер Lenovo' : 'Правило ' . $id,
            'enabled' => true, 'action' => 'extract', 'pattern' => $pattern,
            'serial_group' => (int) $old['serial_group'],
            'extra' => $old['part_group'] ? 'part' : 'none', 'extra_group' => (int) $old['part_group'],
            'check_model' => true,
        ];
    }

    private static function text(mixed $value, string $label, int $max = 255): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            throw new RuntimeException('Некорректное поле: ' . $label);
        }
        return trim($value);
    }

    private static function flag(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException('Переключатель должен иметь значение true или false.');
        }
        return $value;
    }

    private static function key(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $value)) {
            throw new RuntimeException('Некорректный идентификатор профиля, модели или правила.');
        }
        return $value;
    }

    private static function listing(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 200) {
            throw new RuntimeException('Ожидается список не более 200 записей.');
        }
        return $value;
    }

    private static function usbIds(mixed $value, bool $required = false): array
    {
        $ids = self::listing($value);
        if ($required && !$ids) {
            throw new RuntimeException('Укажите хотя бы один VID или PID.');
        }
        foreach ($ids as &$id) {
            if (!is_string($id) || !preg_match('/^[a-fA-F0-9]{4}$/D', $id)) {
                throw new RuntimeException('VID и PID должны состоять из четырёх шестнадцатеричных символов.');
            }
            $id = strtoupper($id);
        }
        unset($id);
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Повторяющиеся VID или PID.');
        }
        return $ids;
    }

    public static function regex(string $pattern): string
    {
        if (strlen($pattern) > 1000 || $pattern === '') {
            throw new RuntimeException('Выражение должно содержать от 1 до 1000 байт.');
        }
        $compiled = '~(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=1000)' . str_replace('~', '\\~', $pattern) . '~uD';
        if (@preg_match($compiled, '') === false) {
            throw new RuntimeException('Некорректное регулярное выражение.');
        }
        return $compiled;
    }

    public static function validate(array $input): array
    {
        $input = self::upgrade($input);
        $out = ['schema_version' => 2];
        foreach (['enabled', 'migrate_existing', 'add_marker', 'dock_location'] as $field) {
            $out[$field] = self::flag($input[$field] ?? null);
        }
        $out['type_name'] = self::text($input['type_name'] ?? null, 'Тип');
        $out['manufacturers'] = [];
        $vendorIds = $vendorNames = $usbPairs = [];
        foreach (self::listing($input['manufacturers'] ?? null) as $vendor) {
            if (!is_array($vendor)) {
                throw new RuntimeException('Некорректный производитель.');
            }
            $v = [
                'id' => self::key($vendor['id'] ?? null),
                'name' => self::text($vendor['name'] ?? null, 'Производитель'),
                'vids' => self::usbIds($vendor['vids'] ?? null, true),
                'enabled' => self::flag($vendor['enabled'] ?? null), 'models' => [], 'rules' => [],
            ];
            $nameKey = mb_strtolower($v['name']);
            if (isset($vendorIds[$v['id']]) || isset($vendorNames[$nameKey])) {
                throw new RuntimeException('Производитель или его идентификатор повторяется.');
            }
            $vendorIds[$v['id']] = $vendorNames[$nameKey] = true;
            $modelIds = $modelCodes = $modelPids = [];
            foreach (self::listing($vendor['models'] ?? null) as $model) {
                if (!is_array($model)) {
                    throw new RuntimeException('Некорректная модель.');
                }
                $m = [
                    'id' => self::key($model['id'] ?? null),
                    'code' => self::text($model['code'] ?? null, 'Модель', 64),
                    'name' => self::text($model['name'] ?? null, 'Название модели'),
                    'pids' => self::usbIds($model['pids'] ?? null),
                    'enabled' => self::flag($model['enabled'] ?? null),
                    'disabled_pids' => self::usbIds($model['disabled_pids'] ?? []),
                ];
                if (array_diff($m['disabled_pids'], $m['pids'])) {
                    throw new RuntimeException('Отключённый PID отсутствует у модели.');
                }
                if (isset($modelIds[$m['id']]) || isset($modelCodes[mb_strtolower($m['code'])])) {
                    throw new RuntimeException('Повторяющаяся модель внутри производителя.');
                }
                $modelIds[$m['id']] = true;
                $modelCodes[mb_strtolower($m['code'])] = true;
                foreach ($m['pids'] as $pid) {
                    if (isset($modelPids[$pid])) {
                        throw new RuntimeException('PID принадлежит нескольким моделям одного производителя.');
                    }
                    $modelPids[$pid] = true;
                    foreach ($v['vids'] as $vid) {
                        $pair = $vid . ':' . $pid;
                        if (isset($usbPairs[$pair])) {
                            throw new RuntimeException('Одинаковые VID/PID нельзя назначить разным моделям.');
                        }
                        $usbPairs[$pair] = true;
                    }
                }
                $v['models'][] = $m;
            }
            $ruleIds = [];
            foreach (self::listing($vendor['rules'] ?? null) as $rule) {
                if (!is_array($rule)) {
                    throw new RuntimeException('Некорректное правило.');
                }
                $r = [
                    'id' => self::key($rule['id'] ?? null),
                    'name' => self::text($rule['name'] ?? null, 'Название правила'),
                    'enabled' => self::flag($rule['enabled'] ?? null),
                    'models' => self::listing($rule['models'] ?? null),
                    'pids' => self::usbIds($rule['pids'] ?? null),
                    'action' => $rule['action'] ?? null,
                    'pattern' => $rule['pattern'] ?? '',
                    'serial_group' => $rule['serial_group'] ?? 1,
                    'extra' => $rule['extra'] ?? 'none',
                    'extra_group' => $rule['extra_group'] ?? 0,
                    'check_model' => self::flag($rule['check_model'] ?? false),
                ];
                if (
                    isset($ruleIds[$r['id']]) || !in_array($r['action'], ['extract', 'keep'], true)
                    || !in_array($r['extra'], ['none', 'model', 'part'], true)
                ) {
                    throw new RuntimeException('Повторяющееся правило или неизвестное действие.');
                }
                $ruleIds[$r['id']] = true;
                foreach ($r['models'] as $modelId) {
                    if (!is_string($modelId) || !isset($modelIds[$modelId])) {
                        throw new RuntimeException('Правило ссылается на отсутствующую модель.');
                    }
                }
                if (count($r['models']) !== count(array_unique($r['models']))) {
                    throw new RuntimeException('Повторяющиеся модели правила.');
                }
                foreach ($r['pids'] as $pid) {
                    $allowed = array_filter($v['models'], static fn($m) =>
                        (!$r['models'] || in_array($m['id'], $r['models'], true)) && in_array($pid, $m['pids'], true));
                    if (!$allowed) {
                        throw new RuntimeException('PID правила отсутствует у выбранных моделей.');
                    }
                }
                if (
                    !is_string($r['pattern']) || !is_int($r['serial_group']) || $r['serial_group'] < 1
                    || $r['serial_group'] > 9 || !is_int($r['extra_group']) || $r['extra_group'] < 0
                    || $r['extra_group'] > 9 || ($r['extra'] !== 'none' && $r['extra_group'] === 0)
                ) {
                    throw new RuntimeException('Некорректные группы извлечения.');
                }
                if ($r['action'] === 'extract') {
                    self::regex($r['pattern']);
                }
                $v['rules'][] = $r;
            }
            $out['manufacturers'][] = $v;
        }
        if (strlen(json_encode($out, JSON_THROW_ON_ERROR)) > 300000) {
            throw new RuntimeException('Настройки превышают 300 КБ.');
        }
        return $out;
    }

    public static function catalog(?string $vendorId = null): array
    {
        $out = [];
        foreach (self::read()['manufacturers'] as $v) {
            if (!$v['enabled'] || ($vendorId !== null && $vendorId !== $v['id'])) {
                continue;
            }
            foreach ($v['models'] as $m) {
                if (!$m['enabled']) {
                    continue;
                }
                foreach ($v['vids'] as $vid) {
                    foreach (array_diff($m['pids'], $m['disabled_pids'] ?? []) as $pid) {
                        $out[$vid . ':' . $pid] = [
                            'vid' => $vid, 'pid' => $pid, 'model' => $m['code'], 'model_id' => $m['id'],
                            'name' => $m['name'], 'manufacturer' => $v['name'], 'manufacturer_key' => $v['id'],
                        ];
                    }
                }
            }
        }
        return $out;
    }

    public static function info(string $vid, string $pid, string $raw, ?string $forcedRule = null): ?array
    {
        $settings = self::read();
        $vid = strtoupper(preg_replace('/^0x/i', '', trim($vid)));
        $pid = strtoupper(preg_replace('/^0x/i', '', trim($pid)));
        $raw = strtoupper(trim($raw));
        if (
            !$settings['enabled'] || $raw === '' || strlen($raw) > 255 || str_contains($raw, '&')
            || preg_match('/^(?:0+|UNKNOWN|N\/A|NONE|NOT AVAILABLE)$/D', $raw)
        ) {
            return null;
        }
        $entry = self::catalog()[$vid . ':' . $pid] ?? null;
        if (!$entry) {
            return null;
        }
        $vendor = array_values(array_filter($settings['manufacturers'], static fn($v) =>
            $v['id'] === $entry['manufacturer_key']))[0];
        $result = $entry + [
            'raw' => $raw, 'serial' => $raw, 'part' => '', 'extra_kind' => 'none',
            'serial_status' => preg_match('/^[A-Z0-9]{8}$/D', $raw) ? 'plain' : 'unrecognized',
            'rule_id' => '', 'rule_name' => '',
        ];
        foreach ($vendor['rules'] as $r) {
            if (
                !$r['enabled'] || ($forcedRule !== null && $forcedRule !== $r['id'])
                || ($r['models'] && !in_array($entry['model_id'], $r['models'], true))
                || ($r['pids'] && !in_array($pid, $r['pids'], true))
            ) {
                continue;
            }
            if ($r['action'] === 'keep') {
                return array_replace($result, [
                    'serial_status' => 'plain', 'rule_id' => $r['id'], 'rule_name' => $r['name'],
                ]);
            }
            $matched = @preg_match(self::regex($r['pattern']), $raw, $matches);
            if ($matched === false) {
                return array_replace($result, [
                    'serial_status' => 'regex_limit', 'rule_id' => $r['id'], 'rule_name' => $r['name'],
                ]);
            }
            if (!$matched) {
                continue;
            }
            $serial = $matches[$r['serial_group']] ?? '';
            $extra = $r['extra'] === 'none' ? '' : ($matches[$r['extra_group']] ?? '');
            $status = $serial === '' ? 'invalid_groups' : 'normalized';
            if (
                ($r['extra'] === 'model' || ($r['check_model'] && strlen($extra) === 4))
                && strcasecmp($extra, $entry['model']) !== 0
            ) {
                $status = 'model_mismatch';
            }
            return array_replace($result, [
                'serial' => $status === 'normalized' ? $serial : $raw,
                'part' => $extra, 'extra_kind' => $r['extra'], 'serial_status' => $status,
                'rule_id' => $r['id'], 'rule_name' => $r['name'],
            ]);
        }
        return $result;
    }

    public static function export(array $settings, ?string $vendorId = null): array
    {
        $settings = self::validate($settings);
        $vendors = $settings['manufacturers'];
        if ($vendorId !== null) {
            $vendors = array_values(array_filter($vendors, static fn($v) => $v['id'] === $vendorId));
            if (!$vendors) {
                throw new RuntimeException('Производитель не найден.');
            }
        }
        return [
            'format' => self::FORMAT, 'version' => 1,
            'settings' => $vendorId === null
                ? array_intersect_key($settings, array_flip(self::GLOBALS)) : new \stdClass(),
            'manufacturers' => $vendors,
        ];
    }

    public static function import(string $json, array $current): array
    {
        if (strlen($json) > 300000) {
            throw new RuntimeException('Файл превышает 300 КБ.');
        }
        $preset = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($preset) || ($preset['format'] ?? '') !== self::FORMAT || ($preset['version'] ?? null) !== 1
            || !is_array($preset['settings'] ?? null) || !is_array($preset['manufacturers'] ?? null)
        ) {
            throw new RuntimeException('Неподдерживаемый формат пресета.');
        }
        $incoming = self::validate(array_replace(self::defaults(), $preset['settings'], [
            'schema_version' => 2, 'manufacturers' => $preset['manufacturers'],
        ]));
        $result = self::validate($current);
        foreach ($preset['settings'] as $key => $value) {
            if (!in_array($key, self::GLOBALS, true)) {
                throw new RuntimeException('Неизвестная общая настройка пресета.');
            }
            $result[$key] = $incoming[$key];
        }
        $changes = [];
        foreach ($incoming['manufacturers'] as $v) {
            $index = null;
            foreach ($result['manufacturers'] as $i => $old) {
                if ($v['id'] === $old['id'] || mb_strtolower($v['name']) === mb_strtolower($old['name'])) {
                    if ($index !== null) {
                        throw new RuntimeException('Идентификатор и название указывают на разные профили.');
                    }
                    $index = $i;
                }
            }
            if ($index !== null) {
                // Retain the installation's stable identity namespace on replacement.
                $v['id'] = $result['manufacturers'][$index]['id'];
                $result['manufacturers'][$index] = $v;
            } else {
                $result['manufacturers'][] = $v;
            }
            $changes[] = ['name' => $v['name'], 'replace' => $index !== null,
                'models' => count($v['models']), 'rules' => count($v['rules'])];
        }
        return ['settings' => self::validate($result), 'changes' => $changes];
    }
}
