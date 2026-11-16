<?php

namespace App\Visca\Admin;

use SilverStripe\Admin\ModelAdmin;
use App\Visca\Model\ViscaCamera;
use App\Model\PanelSettings;

class CameraAdmin extends ModelAdmin
{
    private static array $managed_models = array(
        ViscaCamera::class,
    );

    private static string $url_segment = 'av-cameras';
    private static string $menu_title = 'VISCA Cameras';
    private static string $menu_icon_class = 'font-icon-eye';
    private static int $menu_priority = 50;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/camera')) {
            return false;
        }

        return parent::canView($member);
    }
}
