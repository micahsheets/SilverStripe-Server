<?php

namespace App\Admin;

use App\Model\PanelSettings;
use App\Model\RodeCasterDevice;
use SilverStripe\Admin\ModelAdmin;

class RodeCasterAdmin extends ModelAdmin
{
    private static array $managed_models = [
        RodeCasterDevice::class,
    ];

    private static string $url_segment = 'av-rodecaster';
    private static string $menu_title = 'RØDECaster Video';
    private static string $menu_icon_class = 'font-icon-monitor';
    private static int $menu_priority = 94;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/rodecaster')) {
            return false;
        }

        return parent::canView($member);
    }
}
