<?php

/** SPDX-License-Identifier: MIT */

namespace GlpiPlugin\Itcoreinventory;

use Config;
use Group;
use Html;
use RuntimeException;
use Session;
use Throwable;

final class PluginItcoreinventorySettings
{
    public static array $overrides = [];
    public static function esc($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function read(string $plugin, array $defaults): array
    {
        if (isset(self::$overrides[$plugin])) {
            return self::$overrides[$plugin];
        }
        $all = Config::getConfigurationValues('plugin:' . $plugin);
        $saved = json_decode($all['settings'] ?? '{}', true);
        $v = array_replace($defaults, is_array($saved) ? $saved : []);
        return $v;
    }
    public static function regex(string $pattern): void
    {
        if (
            strlen($pattern) > 5000 || @preg_match('~' . str_replace('~', '\\~', $pattern)
            . '~iu', '') === false
        ) {
            throw new RuntimeException('Некорректное регулярное выражение.');
        }
    }
    public static function value($v, array $f)
    {
        $t = $f['type'] ?? 'text';
        if ($t === 'bool') {
            if (!is_bool($v)) {
                throw new RuntimeException('Ожидается переключатель: '
                . $f['label']);
            }
            return $v;
        }
        if (in_array($t, ['int','group'], true)) {
            if (
                filter_var($v, FILTER_VALIDATE_INT) === false || $v < ($f['min'] ?? 0)
                || $v > ($f['max'] ?? 100000000)
            ) {
                throw new RuntimeException('Недопустимое число: '
                . $f['label']);
            }
            if ($t === 'group' && $v > 0) {
                $g = new Group();
                if (!$g->getFromDB((int)$v)) {
                    throw new RuntimeException('Группа не найдена: '
                    . $f['label']);
                }
            }
            return(int)$v;
        }
        if ($t === 'collectors') {
            if (!is_array($v)) {
                throw new RuntimeException('Ожидается список сборщиков.');
            }
            $ids = [];
            foreach ($v as $id) {
                if (
                    filter_var($id, FILTER_VALIDATE_INT) === false
                    || (int)$id < 1
                ) {
                    throw new RuntimeException('Некорректный сборщик.');
                }
                $ids[] = (int)$id;
            }
            return array_values(array_unique($ids));
        }
        if (!is_string($v) || mb_strlen($v) > ($f['maxlen'] ?? 6000)) {
            throw new RuntimeException('Слишком длинное значение: '
            . $f['label']);
        }
        if (!empty($f['required']) && trim($v) === '') {
            throw new RuntimeException('Заполните поле: ' . $f['label']);
        }
        if ($t === 'regex' && trim($v) !== '') {
            self::regex($v);
        }
        if ($t === 'color' && !preg_match('/^#[0-9a-f]{6}$/iD', $v)) {
            throw new RuntimeException('Ожидается цвет #RRGGBB.');
        }
        if ($t === 'select' && !array_key_exists($v, $f['options'])) {
            throw new RuntimeException('Недопустимый вариант: ' . $f['label']);
        }
        return $v;
    }
    public static function validate(string $plugin, array $v): array
    {
        if ($plugin === 'itcoreinventory') {
            return PluginItcoreinventoryProfiles::validate($v);
        }
        $schema = call_user_func('plugin_' . $plugin . '_settings_schema');
        $d = call_user_func('plugin_' . $plugin . '_settings_defaults');
        $out = $d;
        foreach ($schema['tabs'] as $tab) {
            foreach ($tab['fields'] ?? [] as $f) {
                $key = $f['key'];
                if (($f['type'] ?? '') === 'list') {
                    if (!is_array($v[$key] ?? null) || count($v[$key]) > 200) {
                        throw new RuntimeException('Недопустимый список '
                        . $f['label']);
                    }
                    $rows = [];
                    foreach ($v[$key] as $row) {
                        $r = [];
                        foreach ($f['columns'] as $col) {
                            $r[$col['key']] = self::value($row[$col['key']] ?? null, $col);
                        }
                        $rows[] = $r;
                    }
                    $out[$key] = $rows;
                } else {
                    $out[$key] = self::value($v[$key] ?? $d[$key], $f);
                }
            }
        }
        $fn = 'plugin_' . $plugin . '_settings_validate';
        return function_exists($fn) ? $fn($out) : $out;
    }
    public static function save(string $plugin, array $v, int $revision): void
    {
        global $DB;
        $context = 'plugin:' . $plugin;
        $all = Config::getConfigurationValues($context);
        $current = (int)($all['settings_revision'] ?? 0);
        if ($revision !== $current) {
            throw new RuntimeException('Настройки изменились в другой вкладке. Обновите страницу.');
        }
        $v = self::validate($plugin, $v);
        $old = call_user_func('plugin_' . $plugin . '_settings');
        $history = json_decode($all['settings_history'] ?? '[]', true)
        ?: [];
        $history[] = [
            'id' => bin2hex(random_bytes(8)),
            'user' => (int)Session::getLoginUserID(),
            'date' => date('Y-m-d H:i:s'),
            'value' => $old
        ];
        $history = array_slice($history, -20);
        $DB->beginTransaction();
        try {
            $fn = 'plugin_' . $plugin . '_settings_apply';
            if (function_exists($fn)) {
                $fn($v);
            }
            $values = [
                'settings' => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'settings_revision' => $current + 1,
                'settings_history' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            ];
            Config::setConfigurationValues($context, $values);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }
    public static function page(string $plugin): void
    {
        global $DB;
        Session::checkRight('config', UPDATE);
        $schema = call_user_func('plugin_' . $plugin . '_settings_schema');
        $settings = call_user_func('plugin_' . $plugin . '_settings');
        $all = Config::getConfigurationValues('plugin:' . $plugin);
        $revision = (int)($all['settings_revision'] ?? 0);
        $history = json_decode($all['settings_history'] ?? '[]', true)
        ?: [];
        $csrf = 'itcore_settings_' . $plugin;
        if (!isset($_SESSION[$csrf])) {
            $_SESSION[$csrf] = bin2hex(random_bytes(32));
        }
        $message = '';
        $error = '';
        $preview = null;
        $active = $_POST['active_tab'] ?? array_key_first($schema['tabs']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                if (!hash_equals($_SESSION[$csrf], (string)($_POST['_itcore_csrf'] ?? ''))) {
                    throw new RuntimeException('Обновите страницу: сессия формы устарела.');
                }
                $body = $_POST['settings_json'] ?? '';
                if (strlen($body) > 300000) {
                    throw new RuntimeException('Настройки превышают допустимый размер.');
                }
                $candidate = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($candidate)) {
                    throw new RuntimeException('Некорректные настройки.');
                }
                $settings = $candidate;
                $action = $_POST['action'] ?? '';
                if ($action === 'restore') {
                    $wanted = (string)($_POST['restore_id'] ?? '');
                    $found = null;
                    foreach ($history as $h) {
                        if ($h['id'] === $wanted) {
                            $found = $h['value'];
                        }
                    }
                    if ($found === null) {
                        throw new RuntimeException('Редакция не найдена.');
                    }
                    self::save($plugin, $found, (int)($_POST['revision'] ?? -1));
                    $message = 'Настройки восстановлены.';
                    $active = 'history';
                } elseif ($action === 'preview') {
                    $candidate = self::validate($plugin, $candidate);
                    self::$overrides[$plugin] = $candidate;
                    try {
                        $preview = call_user_func(
                            'plugin_' . $plugin . '_settings_preview',
                            $_POST['preview'] ?? []
                        );
                    } finally {
                        unset(self::$overrides[$plugin]);
                    }
                    $message = 'Проверка выполнена без изменения настроек и отправки писем.';
                    $active = 'preview';
                } elseif ($action === 'save') {
                    self::save($plugin, $candidate, (int)($_POST['revision'] ?? -1));
                    $message = 'Настройки сохранены.';
                } else {
                    throw new RuntimeException('Неизвестное действие.');
                }
                if ($action !== 'preview') {
                    $settings = call_user_func('plugin_' . $plugin
                    . '_settings');
                    $all = Config::getConfigurationValues('plugin:'
                    . $plugin);
                    $revision = (int)($all['settings_revision'] ?? 0);
                    $history = json_decode($all['settings_history'] ?? '[]', true)
                    ?: [];
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $tabs = $schema['tabs'];
        $tabs['preview'] = ['label' => 'Проверка','icon' => 'ti-eye'];
        $tabs['history'] = ['label' => 'История настроек','icon' => 'ti-history'];
        if (!isset($tabs[$active])) {
            $active = array_key_first($tabs);
        }
        Html::header(
            $schema['title'],
            $_SERVER['PHP_SELF'],
            'config',
            'plugins'
        );
        echo Html::css('/plugins/' . $plugin . '/settings.css', [], false);
        $previewFields = [];
        foreach ($schema['preview_fields'] ?? [] as $field) {
            $field['value'] = $_POST['preview'][$field['key']] ?? $field['default'] ?? '';
            $previewFields[] = $field;
        }
        $historyView = array_reverse($history);
        foreach ($historyView as &$entry) {
            $entry['user_label'] = getUserName($entry['user']);
        }
        unset($entry);
        \Glpi\Application\View\TemplateRenderer::getInstance()->display('@itcoreinventory/settings.html.twig', [
            'schema' => $schema,
            'settings' => $settings,
            'tabs' => $tabs,
            'active' => $active,
            'message' => $message,
            'error' => $error,
            'csrf' => $_SESSION[$csrf],
            'glpi_csrf' => Session::getNewCSRFToken(),
            'revision' => $revision,
            'preview' => $preview,
            'preview_fields' => $previewFields,
            'history' => $historyView,
            'migration_url' => $GLOBALS['CFG_GLPI']['root_doc'] . '/plugins/itcoreinventory/front/migration.php',
        ]);
        echo Html::script(
            '/plugins/' . $plugin . '/settings.js',
            ['version' => 'itcore-settings-1'],
            false
        );
        Html::footer();
    }
}
