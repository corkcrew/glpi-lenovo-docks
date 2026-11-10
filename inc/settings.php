<?php

/** SPDX-License-Identifier: MIT */

function plugin_itcoreinventory_settings_defaults(): array
{
    return PluginItcoreinventoryProfiles::defaults();
}
function plugin_itcoreinventory_settings(): array
{
    return PluginItcoreinventoryProfiles::read();
}
function plugin_itcoreinventory_settings_validate(array $settings): array
{
    return PluginItcoreinventoryProfiles::validate($settings);
}
