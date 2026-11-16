<?php

namespace App\DTX\Admin;

use SilverStripe\Admin\ModelAdmin;
use App\DTX\Model\DTXDevice;
use App\Model\PanelSettings;

class DTXAdmin extends ModelAdmin
{
    private static array $managed_models = array(
        DTXDevice::class,
    );

    private static string $url_segment = 'av-audio';
    private static string $menu_title = 'Audio DSP (DTX)';
    private static string $menu_icon_class = 'font-icon-sliders';
    private static int $menu_priority = 90;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/audio')) {
            return false;
        }

        return parent::canView($member);
    }
}
