<?php

namespace App\AHM\Admin;

use App\AHM\Model\AHMDevice;
use SilverStripe\Admin\ModelAdmin;

class AHMAdmin extends ModelAdmin
{
    private static string $url_segment = 'ahm';

    private static string $menu_title = 'Allen & Heath AHM';

    private static string $menu_icon_class = 'font-icon-sliders';

    private static int $menu_priority = 62;

    private static array $managed_models = [
        AHMDevice::class,
    ];
}
