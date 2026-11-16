<?php

namespace App\VPX\Admin;

use App\Model\PanelSettings;
use App\VPX\Model\RoutePreset;
use App\VPX\Model\VPXDevice;
use App\VPX\Model\VPXSettings;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;

class VPXMatrixAdmin extends ModelAdmin
{
    private static array $managed_models = [
        VPXDevice::class,
        RoutePreset::class,
        VPXSettings::class,
    ];

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

    public function getEditForm($id = null, $fields = null)
    {
        if ($this->modelClass === VPXSettings::class) {
            VPXSettings::current();
        }

        $form = parent::getEditForm($id, $fields);

        if ($this->modelClass === VPXSettings::class) {
            $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName(VPXSettings::class));
            if ($gridField instanceof GridField) {
                $config = $gridField->getConfig();
                $config->removeComponentsByType(GridFieldAddNewButton::class);
                $config->removeComponentsByType(GridFieldDeleteAction::class);
            }
        }

        return $form;
    }
}
