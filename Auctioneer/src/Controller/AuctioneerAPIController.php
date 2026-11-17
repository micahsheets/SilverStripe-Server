<?php

namespace App\Auctioneer\Controller;

use App\Auctioneer\Model\AuctioneerProfile;
use App\Auctioneer\Model\AuctionLane;
use App\Auctioneer\Model\AuctionPanelStation;
use App\Auctioneer\Service\AuctioneerDSPService;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\SecurityToken;
use Throwable;

class AuctioneerAPIController extends Controller
{
    private static string $url_segment = 'api/auctioneer';

    private static array $allowed_actions = [
        'bootstrap',
        'login',
        'logout',
        'apply',
        'saveprofile',
        'createprofile',
    ];

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();

        if (!PanelSecurityGuard::isIPAllowed($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'IP not allowed.'], 403));
        }

        if ($request->isPOST() && !PanelSecurityGuard::isValidCSRF($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403));
        }
    }

    public function bootstrap(HTTPRequest $request): HTTPResponse
    {
        $stationId = (int) $request->getVar('station');
        $station = $stationId > 0 ? AuctionPanelStation::get()->byID($stationId) : null;

        $lanes = [];
        foreach (AuctionLane::get() as $lane) {
            $stations = [];
            foreach ($lane->Stations() as $st) {
                $stations[] = $this->serializeStation($st);
            }
            $lanes[] = [
                'id'         => (int) $lane->ID,
                'laneNumber' => (int) $lane->LaneNumber,
                'title'      => $lane->Title,
                'dtxTitle'   => $lane->DTXDevice()->exists() ? $lane->DTXDevice()->Title : 'Unassigned DTX',
                'stations'   => $stations,
            ];
        }

        $auctioneers = array_map(fn(AuctioneerProfile $a) => [
            'id'    => (int) $a->ID,
            'title' => $a->Title,
        ], AuctioneerProfile::get()->filter('Active', 1)->toArray());

        return $this->jsonResponse([
            'success'       => true,
            'csrfToken'     => SecurityToken::inst()->getValue(),
            'activeStation' => $station ? $this->serializeStation($station) : null,
            'lanes'         => $lanes,
            'auctioneers'   => $auctioneers,
        ]);
    }

    public function login(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $station = AuctionPanelStation::get()->byID((int) ($data['station_id'] ?? 0));
            $profile = AuctioneerProfile::get()->filter([
                'ID'     => (int) ($data['auctioneer_id'] ?? 0),
                'Active' => 1,
            ])->first();
            $pin = trim((string) ($data['pin'] ?? ''));

            if (!$station) {
                return $this->jsonResponse(['success' => false, 'error' => 'Touch panel station not selected.'], 404);
            }
            if (!$profile) {
                return $this->jsonResponse(['success' => false, 'error' => 'Auctioneer not found.'], 404);
            }
            if ($pin === '' || !hash_equals((string) $profile->PinCode, $pin)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Incorrect PIN. Try again.'], 401);
            }

            $applied = AuctioneerDSPService::singleton()->loginAuctioneer($station, $profile);

            return $this->jsonResponse([
                'success' => true,
                'station' => $this->serializeStation($station),
                'applied' => $applied,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function logout(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $station = AuctionPanelStation::get()->byID((int) ($data['station_id'] ?? 0));
            if (!$station) {
                return $this->jsonResponse(['success' => false, 'error' => 'Station not found.'], 404);
            }

            $applied = AuctioneerDSPService::singleton()->logoutStation($station);

            return $this->jsonResponse([
                'success' => true,
                'station' => $this->serializeStation($station),
                'applied' => $applied,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function apply(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $station = AuctionPanelStation::get()->byID((int) ($data['station_id'] ?? 0));
            if (!$station || !$station->ActiveAuctioneer()->exists()) {
                return $this->jsonResponse(['success' => false, 'error' => 'No active auctioneer logged in on this panel.'], 403);
            }

            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $applied = AuctioneerDSPService::singleton()->applySettingsToStation($station, $settings);

            return $this->jsonResponse([
                'success' => true,
                'applied' => $applied,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function saveprofile(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $station = AuctionPanelStation::get()->byID((int) ($data['station_id'] ?? 0));
            $profile = $station ? $station->ActiveAuctioneer() : null;
            if (!$station || !$profile || !$profile->exists()) {
                return $this->jsonResponse(['success' => false, 'error' => 'No active auctioneer session to save.'], 403);
            }

            $uiSettings = is_array($data['settings'] ?? null) ? $data['settings'] : $profile->toDspSettingsArray();
            $merged = AuctioneerDSPService::singleton()->readLiveSettingsFromStation($station, $uiSettings);

            $profile->applyDspSettingsArray($merged);
            $profile->write();

            AuctioneerDSPService::singleton()->applySettingsToStation($station, $profile->toDspSettingsArray());

            return $this->jsonResponse([
                'success' => true,
                'station' => $this->serializeStation($station),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function createprofile(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $name = trim((string) ($data['name'] ?? ''));
            $pin = trim((string) ($data['pin'] ?? ''));

            $profile = AuctioneerProfile::create([
                'Title'   => $name,
                'PinCode' => $pin,
                'Active'  => 1,
            ]);
            $profile->write();

            return $this->jsonResponse([
                'success'    => true,
                'auctioneer' => [
                    'id'    => (int) $profile->ID,
                    'title' => $profile->Title,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function serializeStation(AuctionPanelStation $station): array
    {
        $lane = $station->Lane();
        $active = $station->ActiveAuctioneer();
        $hasActive = $active && $active->exists();

        return [
            'id'         => (int) $station->ID,
            'title'      => $station->Title,
            'dtxChannel' => (int) $station->DTXChannel,
            'laneId'     => $lane && $lane->exists() ? (int) $lane->ID : 0,
            'laneTitle'  => $lane && $lane->exists() ? $lane->Title : 'Unassigned Lane',
            'dtxTitle'   => ($lane && $lane->exists() && $lane->DTXDevice()->exists()) ? $lane->DTXDevice()->Title : 'No DTX',
            'auctioneer' => $hasActive ? [
                'id'       => (int) $active->ID,
                'title'    => $active->Title,
                'settings' => $active->toDspSettingsArray(),
            ] : null,
            'defaultSettings' => ($lane && $lane->exists())
                ? $lane->getDefaultDspSettingsArray()
                : AuctionLane::create()->getDefaultDspSettingsArray(),
        ];
    }

    private function parseRequestData(HTTPRequest $request): array
    {
        $raw = (string) $request->getBody();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $request->postVars();
    }

    private function jsonResponse(array $payload, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload), $status)
            ->addHeader('Content-Type', 'application/json');
    }
}
