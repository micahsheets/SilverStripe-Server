<?php

namespace App\Admin;

use App\Model\EnttecDevice;
use App\Model\Location;
use App\Model\PanelSettings;
use App\Model\RouteAuditLog;
use App\Model\RoutePreset;
use App\Model\VPXDevice;
use SilverStripe\Admin\ModelAdmin;

class AVSystemAdmin extends ModelAdmin
{
    private static string $url_segment = 'av-system';

    private static string $menu_title = 'AV System';

    private static string $menu_icon_class = 'font-icon-sliders';

    private static array $managed_models = [
        VPXDevice::class,
        RoutePreset::class,
        EnttecDevice::class,
        Location::class,
        PanelSettings::class,
        RouteAuditLog::class,
    ];
}
