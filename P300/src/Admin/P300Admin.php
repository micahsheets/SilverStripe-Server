<?php

namespace App\P300\Admin;

use App\Model\PanelSettings;
use App\P300\Model\P300AuditLog;
use App\P300\Model\P300Device;
use SilverStripe\Admin\ModelAdmin;

class P300Admin extends ModelAdmin
{
    private static string $url_segment = 'p300-dsp';

    private static string $menu_title = 'Shure P300 DSP';

    private static string $menu_icon_class = 'font-icon-sliders';

    private static int $menu_priority = 60;

    private static array $managed_models = [
        P300Device::class,
        P300AuditLog::class,
    ];

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/p300')) {
            return false;
        }

        return parent::canView($member);
    }
}
