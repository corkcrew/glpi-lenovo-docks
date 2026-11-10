# Changelog

## 2.1.1

- Manufacturer profiles with multiple USB VID values and model-specific PID lists.
- Ordered serial-processing rules with configurable scope and extraction groups.
- GLPI-themed settings tabs with add, edit and delete controls.
- GLPI datatable views with linked records, search, display sorting, column selection,
  pagination, CSV export and bulk settings actions without changing the storage format.
- Versioned JSON presets with import preview, export and settings history.
- Existing Lenovo settings and dock identities preserved during upgrade.
- Only Lenovo presets included; other manufacturers require manual configuration
  and compatibility is not guaranteed.

## 2.1.0

- Independent repository containing only docking station functionality.
- MIT license, PHP-FIG PSR-12 checks and PSR-4 class layout.
- Native DockStation definition created by the standard GLPI plugin installer.
- Automatic discovery and migration of existing Peripheral dock records, including
  generic USB cards backed by VID/PID inventory evidence.
- Dedicated migration preview and execution page with CSRF protection.
- Safe duplicate consolidation, preserved supported relationships and archived sources.
- Queue-based evidence for devices whose historical inventory file is unavailable.
- Existing administrator cron schedule preserved during updates.
- Journaled transactional uninstall returns docks and supported references to core
  Peripheral records; retains later edits and newly discovered docks.
- Removal preflight and repeated install/uninstall regression tests; refuses exact
  automatic return for old migrations without sufficient provenance.
- Twig settings template and automated unit/integration tests.

## 2.0.1

- Added short Lenovo compound serial format and configurable per-model expressions.
- Added serial auditing without overwriting manually locked fields.

## 2.0.0

- Introduced native GLPI DockStation assets and separated user-linking functionality.
