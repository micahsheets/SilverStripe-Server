<?php

namespace App\Auctioneer\Admin;

use App\Auctioneer\Model\AuctioneerProfile;
use App\Auctioneer\Model\AuctionLane;
use App\Auctioneer\Model\AuctionPanelStation;
use App\DTX\Model\DTXDevice;
use App\Model\PanelSettings;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FormAction;

class AuctioneerAdmin extends ModelAdmin
{
    private static array $managed_models = [
        AuctioneerProfile::class,
        AuctionLane::class,
        AuctionPanelStation::class,
    ];

    private static string $url_segment = 'auctioneers';
    private static string $menu_title = 'Auctioneer Mics';
    private static string $menu_icon_class = 'font-icon-torsos-all';
    private static int $menu_priority = 85;

    public function canView($member = null)
    {
        if (!PanelSettings::isModuleRouteEnabled('/panel/auctioneer')) {
            return false;
        }
        return parent::canView($member);
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        if ($this->modelClass === AuctionLane::class && AuctionLane::get()->count() === 0) {
            $form->Actions()->push(
                FormAction::create('doSeedLanes', 'Quick-Create 16 Lanes (4 Panels Each)')
                    ->addExtraClass('btn btn-primary font-icon-plus-circled')
            );
        }

        return $form;
    }

    public function doSeedLanes($data, $form): HTTPResponse
    {
        $dtxList = DTXDevice::get()->filter('Active', 1)->sort('ID ASC')->toArray();

        for ($i = 1; $i <= 16; $i++) {
            $dtxIndex = (int) floor(($i - 1) / 4);
            $dtx = $dtxList[$dtxIndex] ?? ($dtxList[0] ?? null);

            $lane = AuctionLane::create([
                'LaneNumber'  => $i,
                'Title'       => sprintf('Lane %02d', $i),
                'DTXDeviceID' => $dtx ? (int) $dtx->ID : 0,
            ]);
            $lane->write();

            for ($ch = 1; $ch <= 4; $ch++) {
                AuctionPanelStation::create([
                    'LaneID'     => $lane->ID,
                    'Title'      => "Block Mic {$ch}",
                    'DTXChannel' => $ch,
                    'SortOrder'  => $ch,
                ])->write();
            }
        }

        return $this->redirectBack();
    }
}
