<?php

namespace App\Admin;

use SilverStripe\Admin\ModelAdmin;
use App\Model\SystemModule;
use App\Model\PanelSettings;

class AVSettingsAdmin extends ModelAdmin
{
    private static array $managed_models = [
        PanelSettings::class,
        SystemModule::class,
    ];

    private static string $url_segment = 'av-settings';
    private static string $menu_title = 'AV System';
    private static string $menu_icon_class = 'font-icon-cog';
    private static int $menu_priority = 120;
}
