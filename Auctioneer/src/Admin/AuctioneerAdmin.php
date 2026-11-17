<?php

namespace App\Auctioneer\Admin;

use App\Auctioneer\Model\AuctioneerProfile;
use App\Auctioneer\Model\AuctionLane;
use App\Auctioneer\Model\AuctionPanelStation;
use App\DTX\Model\DTXDevice;
use App\Model\PanelSettings;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\SecurityToken;

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

    private static array $allowed_actions = [
        'reorderstations',
    ];

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

        if ($this->modelClass === AuctionPanelStation::class) {
            $csrf = SecurityToken::inst()->getValue();
            $form->Fields()->push(LiteralField::create('StationDragSortScript', <<<HTML
<style>
.grid-field__table tbody tr[data-id] { cursor: grab; }
.grid-field__table tbody tr.auc-dragging { opacity: 0.45; background: rgba(37, 99, 235, 0.14); }
.grid-field__table tbody tr.auc-drag-over { border-top: 2px solid #2563eb; }
</style>
<script>
(function() {
    function bindStationDragSort() {
        var tbody = document.querySelector('.grid-field__table tbody');
        if (!tbody || tbody.dataset.dragBound === '1') return;
        tbody.dataset.dragBound = '1';
        var dragged = null;

        tbody.querySelectorAll('tr[data-id]').forEach(function(row) {
            row.setAttribute('draggable', 'true');
            row.addEventListener('dragstart', function(e) {
                dragged = row;
                row.classList.add('auc-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            row.addEventListener('dragend', function() {
                row.classList.remove('auc-dragging');
                tbody.querySelectorAll('tr').forEach(function(r) { r.classList.remove('auc-drag-over'); });
                var ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(function(r) { return Number(r.dataset.id); });
                fetch('/admin/auctioneers/reorderstations', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-SecurityID': '{$csrf}' },
                    body: JSON.stringify({ ids: ids })
                });
            });
            row.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (!dragged || dragged === row) return;
                row.classList.add('auc-drag-over');
            });
            row.addEventListener('dragleave', function() {
                row.classList.remove('auc-drag-over');
            });
            row.addEventListener('drop', function(e) {
                e.preventDefault();
                row.classList.remove('auc-drag-over');
                if (!dragged || dragged === row) return;
                var rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
                if (rows.indexOf(dragged) < rows.indexOf(row)) {
                    row.after(dragged);
                } else {
                    row.before(dragged);
                }
            });
        });
    }
    bindStationDragSort();
    setInterval(bindStationDragSort, 800);
})();
</script>
HTML
            ));
        }

        return $form;
    }

    public function reorderstations(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return HTTPResponse::create('Method Not Allowed', 405);
        }
        $payload = json_decode((string) $request->getBody(), true);
        $ids = is_array($payload['ids'] ?? null) ? $payload['ids'] : [];
        foreach ($ids as $index => $id) {
            $station = AuctionPanelStation::get()->byID((int) $id);
            if ($station) {
                $station->SortOrder = ($index + 1) * 10;
                $station->write();
            }
        }
        return HTTPResponse::create(json_encode(['success' => true]), 200)
            ->addHeader('Content-Type', 'application/json');
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
                    'SortOrder'  => 0,
                ])->write();
            }
        }

        return $this->redirectBack();
    }
}
