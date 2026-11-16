<?php

namespace App\Admin;

use SilverStripe\Admin\ModelAdmin;
use App\Model\Location;

class LocationAdmin extends ModelAdmin
{
    private static array $managed_models = array(
        Location::class,
    );

    private static string $url_segment = 'av-locations';
    private static string $menu_title = 'Locations';
    private static string $menu_icon_class = 'font-icon-globe';
    private static int $menu_priority = 110;
}
