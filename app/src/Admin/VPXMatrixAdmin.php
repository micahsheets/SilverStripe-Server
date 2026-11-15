<?php

namespace App\Admin;

use SilverStripe\Admin\ModelAdmin;
use App\Model\VPXDevice;
use App\Model\RoutePreset;
use App\Model\PanelSettings;

class VPXMatrixAdmin extends ModelAdmin
{
    private static array $managed_models = array(
        VPXDevice::class,
        RoutePreset::class,
    );

    private static string $url_segment = 'av-matrix';
    private static string $menu_title = 'Video Matrix';
    private static string $menu_icon_class = 'font-icon-monitor';
    private static int $menu_priority = 100;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/video')) {
            return false;
        }

        return parent::canView($member);
    }
}
