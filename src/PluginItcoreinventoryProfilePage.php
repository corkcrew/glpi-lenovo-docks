<?php

/** SPDX-License-Identifier: MIT */

namespace GlpiPlugin\Itcoreinventory;

use Config;
use Html;
use RuntimeException;
use Session;
use Throwable;
use Glpi\Application\View\TemplateRenderer;

final class PluginItcoreinventoryProfilePage
{
    public static function page(): void
    {
        Session::checkRight('config', UPDATE);
        $context = 'plugin:itcoreinventory';
        $config = Config::getConfigurationValues($context);
        $settings = PluginItcoreinventoryProfiles::read();
        $revision = (int) ($config['settings_revision'] ?? 0);
        $csrfKey = 'itcore_dock_profiles_csrf';
        $_SESSION[$csrfKey] ??= bin2hex(random_bytes(32));
        $message = $error = '';
        $preview = null;
        $importPreview = null;
        $importJson = '';
        $dirty = false;
        $active = is_string($_POST['active_tab'] ?? null) ? $_POST['active_tab'] : 'general';
        $vendor = is_string($_POST['selected_vendor'] ?? null) ? $_POST['selected_vendor'] : '';
        $test = ['vid' => '17EF', 'pid' => '30A9', 'serial' => '', 'rule' => 'auto'];
        $history = json_decode($config['settings_history'] ?? '[]', true, 64, JSON_THROW_ON_ERROR);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (
                    !is_string($_POST['_itcore_csrf'] ?? null)
                    || !hash_equals($_SESSION[$csrfKey], $_POST['_itcore_csrf'])
                ) {
                    throw new RuntimeException('Сессия формы устарела. Обновите страницу.');
                }
                $json = $_POST['settings_json'] ?? '';
                if (!is_string($json) || strlen($json) > 300000) {
                    throw new RuntimeException('Некорректный размер настроек.');
                }
                $draft = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($draft)) {
                    throw new RuntimeException('Ожидается объект настроек.');
                }
                $draft = PluginItcoreinventoryProfiles::validate($draft);
                $settings = $draft;
                $dirty = true;
                $action = $_POST['action'] ?? '';
                if ($action === 'save') {
                    $postedRevision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
                    if ($postedRevision === false) {
                        throw new RuntimeException('Некорректная редакция настроек.');
                    }
                    PluginItcoreinventorySettings::save('itcoreinventory', $draft, $postedRevision);
                    $config = Config::getConfigurationValues($context);
                    $revision = (int) $config['settings_revision'];
                    $history = json_decode($config['settings_history'], true, 64, JSON_THROW_ON_ERROR);
                    $message = 'Настройки сохранены.';
                    $dirty = false;
                } elseif ($action === 'export') {
                    $scope = $_POST['export_scope'] ?? '';
                    if (!in_array($scope, ['all', 'vendor'], true)) {
                        throw new RuntimeException('Выберите область экспорта.');
                    }
                    $preset = PluginItcoreinventoryProfiles::export($draft, $scope === 'all' ? null : $vendor);
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="dock-preset.json"');
                    header('Cache-Control: no-store');
                    header('X-Content-Type-Options: nosniff');
                    echo json_encode($preset, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    exit;
                } elseif (in_array($action, ['import_preview', 'import_apply'], true)) {
                    $importJson = $_POST['import_json'] ?? '';
                    if (!is_string($importJson)) {
                        throw new RuntimeException('Некорректный файл пресета.');
                    }
                    $result = PluginItcoreinventoryProfiles::import($importJson, $draft);
                    $active = 'presets';
                    if ($action === 'import_preview') {
                        $importPreview = $result['changes'];
                        $message = 'Файл проверен. Настройки ещё не изменены.';
                    } else {
                        $settings = $result['settings'];
                        $message = 'Пресет загружен в форму. Проверьте результат и нажмите «Сохранить».';
                    }
                } elseif ($action === 'restore') {
                    $id = $_POST['restore_id'] ?? '';
                    $found = null;
                    foreach ($history as $entry) {
                        if ($entry['id'] === $id) {
                            $found = $entry['value'];
                        }
                    }
                    if (!is_array($found)) {
                        throw new RuntimeException('Редакция не найдена.');
                    }
                    $settings = PluginItcoreinventoryProfiles::validate($found);
                    $message = 'Редакция загружена в форму. Для применения нажмите «Сохранить».';
                    $active = 'history';
                } elseif ($action === 'preview') {
                    foreach ($test as $key => $default) {
                        $value = $_POST['preview'][$key] ?? $default;
                        if (!is_string($value) || strlen($value) > 512) {
                            throw new RuntimeException('Некорректные данные проверки.');
                        }
                        $test[$key] = $value;
                    }
                    PluginItcoreinventorySettings::$overrides['itcoreinventory'] = $draft;
                    try {
                        $preview = PluginItcoreinventoryProfiles::info(
                            $test['vid'],
                            $test['pid'],
                            $test['serial'],
                            $test['rule'] === 'auto' ? null : $test['rule']
                        ) ?? false;
                        if ($preview && $preview['manufacturer_key'] !== $vendor) {
                            $preview = false;
                        }
                    } finally {
                        unset(PluginItcoreinventorySettings::$overrides['itcoreinventory']);
                    }
                    $active = 'preview';
                } else {
                    throw new RuntimeException('Неизвестное действие.');
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                $postedRevision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
                if ($postedRevision !== false) {
                    $revision = $postedRevision;
                }
            }
        }
        $tabs = [
            'general' => ['label' => 'Общие настройки', 'icon' => 'ti-settings'],
            'vendors' => ['label' => 'Производители', 'icon' => 'ti-building'],
            'models' => ['label' => 'Модели и PID', 'icon' => 'ti-device-usb'],
            'rules' => ['label' => 'Правила обработки', 'icon' => 'ti-list-numbers'],
            'preview' => ['label' => 'Проверка', 'icon' => 'ti-eye'],
            'presets' => ['label' => 'Импорт и экспорт', 'icon' => 'ti-arrows-exchange'],
            'history' => ['label' => 'История настроек', 'icon' => 'ti-history'],
        ];
        if (!isset($tabs[$active])) {
            $active = 'general';
        }
        if (!in_array($vendor, array_column($settings['manufacturers'], 'id'), true)) {
            $vendor = $settings['manufacturers'][0]['id'] ?? '';
        }
        $history = array_reverse($history);
        foreach ($history as &$entry) {
            $entry['user_label'] = $entry['user'] ? getUserName($entry['user']) : 'Система';
        }
        unset($entry);
        Html::header('Док-станции', $_SERVER['PHP_SELF'], 'config', 'plugins');
        echo Html::css('/plugins/itcoreinventory/profiles.css', [
            'version' => hash_file('sha256', dirname(__DIR__) . '/public/profiles.css'),
        ], false);
        TemplateRenderer::getInstance()->display('@itcoreinventory/profiles.html.twig', [
            'settings' => $settings, 'revision' => $revision, 'csrf' => $_SESSION[$csrfKey],
            'glpi_csrf' => Session::getNewCSRFToken(), 'message' => $message, 'error' => $error,
            'tabs' => $tabs, 'active' => $active, 'vendor' => $vendor, 'test' => $test,
            'preview' => $preview, 'import_preview' => $importPreview, 'import_json' => $importJson,
            'history' => $history, 'dirty' => $dirty,
            'migration_url' => $GLOBALS['CFG_GLPI']['root_doc'] . '/plugins/itcoreinventory/front/migration.php',
        ]);
        echo Html::script('/plugins/itcoreinventory/settings-list.js', [
            'version' => hash_file('sha256', dirname(__DIR__) . '/public/settings-list.js'),
        ], false);
        echo Html::script('/plugins/itcoreinventory/profiles.js', [
            'version' => hash_file('sha256', dirname(__DIR__) . '/public/profiles.js'),
        ], false);
        Html::footer();
    }
}
