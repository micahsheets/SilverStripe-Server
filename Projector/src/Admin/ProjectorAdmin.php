<?php

namespace App\Projector\Admin;

use App\Model\PanelSettings;
use App\Projector\Model\ProjectorDevice;
use SilverStripe\Admin\ModelAdmin;

class ProjectorAdmin extends ModelAdmin
{
    private static array $managed_models = [
        ProjectorDevice::class,
    ];

    private static string $url_segment = 'projectors';
    private static string $menu_title = 'Projectors';
    private static string $menu_icon_class = 'font-icon-monitor';
    private static int $menu_priority = 40;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/projector')) {
            return false;
        }

        return parent::canView($member);
    }
}
