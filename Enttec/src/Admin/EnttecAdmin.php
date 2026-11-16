<?php

namespace App\Enttec\Admin;

use SilverStripe\Admin\ModelAdmin;
use App\Enttec\Model\EnttecDevice;
use App\Model\PanelSettings;

class EnttecAdmin extends ModelAdmin
{
    private static array $managed_models = array(
        EnttecDevice::class,
    );

    private static string $url_segment = 'av-lighting';
    private static string $menu_title = 'Lighting (Enttec)';
    private static string $menu_icon_class = 'font-icon-sun';
    private static int $menu_priority = 70;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/lighting')) {
            return false;
        }

        return parent::canView($member);
    }
}
