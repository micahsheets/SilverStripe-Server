<?php

namespace App\IBT\Admin;

use App\IBT\Model\IBTDevice;
use App\IBT\Model\IBTRoutePreset;
use App\IBT\Model\IBTSettings;
use App\Model\PanelSettings;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;

class IBTMatrixAdmin extends ModelAdmin
{
    private static array $managed_models = [
        IBTDevice::class,
        IBTRoutePreset::class,
        IBTSettings::class,
    ];

    private static string $url_segment = 'ibt-matrix';
    private static string $menu_title = 'IBT-1-BX Matrix';
    private static string $menu_icon_class = 'font-icon-monitor';
    private static int $menu_priority = 99;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/ibt')) {
            return false;
        }
        return parent::canView($member);
    }

    public function getEditForm($id = null, $fields = null)
    {
        if ($this->modelClass === IBTSettings::class) {
            IBTSettings::current();
        }

        $form = parent::getEditForm($id, $fields);

        if ($this->modelClass === IBTSettings::class) {
            $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName(IBTSettings::class));
            if ($gridField instanceof GridField) {
                $config = $gridField->getConfig();
                $config->removeComponentsByType(GridFieldAddNewButton::class);
                $config->removeComponentsByType(GridFieldDeleteAction::class);
            }
        }

        return $form;
    }
}
