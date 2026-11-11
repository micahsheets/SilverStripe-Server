<?php

namespace App\Admin;

use App\Model\Location;
use App\Model\RoutePreset;
use App\Model\VPXDevice;
use SilverStripe\Admin\ModelAdmin;

class AVSystemAdmin extends ModelAdmin
{
    private static string $url_segment = 'av-system';

    private static string $menu_title = 'AV System';

    private static string $menu_icon_class = 'font-icon-monitor';

    private static array $managed_models = [
        VPXDevice::class,
        RoutePreset::class,
        Location::class,
    ];
}
