<?php

namespace App\Controller;

use App\Model\EnttecDevice;
use App\Model\Location;
use App\Model\PanelSettings;
use App\Security\VPXPermissionProvider;
use App\Security\VPXSecurityGuard;
use App\Service\EnttecSPlayService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\SecurityToken;
use Throwable;

class EnttecController extends Controller
{
    private static string $url_segment = 'panel/lighting';

    private static array $allowed_actions = [
        'index',
        'status',
        'playlists',
        'play',
        'blackout',
        'recordStatic',
        'armDynamic',
        'stopDynamic',
        'deletePlaylist',
    ];

    private EnttecSPlayService $splayService;

    public function __construct()
    {
        parent::__construct();
        $this->splayService = EnttecSPlayService::singleton();
    }

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();
        $isApi = str_starts_with(trim($request->getURL(), '/'), 'api/enttec');

        if (!VPXSecurityGuard::isIPAllowed($request)) {
            if ($isApi) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'IP not allowed.'], 403));
            }
            $this->httpError(403, 'Access denied from this network.');
        }

        if (!VPXSecurityGuard::isAuthenticated($request)) {
            if ($isApi) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Auth required.'], 401));
            }
            $this->redirect('/panel');
            return;
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC)) {
            if ($isApi) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Lighting permission required.'], 403));
            }
            $this->httpError(403, 'You do not have permission to access Enttec Lighting Control.');
        }

        if ($isApi && $request->isPOST() && !VPXSecurityGuard::isValidCSRF($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403));
        }
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        return $this->customise([
            'SiteConfig' => PanelSettings::current(),
            'CSRFToken' => SecurityToken::inst()->getValue(),
        ])->renderWith(['App/Controller/TouchPanelLighting']);
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $locations = array_map(fn(Location $l) => [
            'id' => (int) $l->ID,
            'title' => $l->Title,
        ], Location::get()->toArray());

        $devices = array_map(fn(EnttecDevice $d) => [
            'id' => (int) $d->ID,
            'title' => $d->Title,
            'ip' => $d->IPAddress,
            'model' => $d->Model ?: EnttecDevice::MODEL_SP1,
            'isMini' => $d->isMini(),
            'universes' => max(1, (int) $d->Universes),
            'locationId' => (int) $d->LocationID,
        ], EnttecDevice::get()->toArray());

        return $this->jsonResponse([
            'success' => true,
            'csrfToken' => SecurityToken::inst()->getValue(),
            'locations' => $locations,
            'devices' => $devices,
        ]);
    }

    public function playlists(HTTPRequest $request): HTTPResponse
    {
        try {
            $device = $this->resolveDevice((int) $request->getVar('device_id'));
            $items = $this->splayService->getPlaylistsAndCues($device);
            return $this->jsonResponse([
                'success' => true,
                'playlists' => $items,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function play(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $device = $this->resolveDevice((int) ($data['device_id'] ?? 0));
            $playlistId = (int) ($data['playlist_id'] ?? -1);
            $playlistUuid = isset($data['playlist_uuid']) ? (int) $data['playlist_uuid'] : null;
            $label = trim((string) ($data['name'] ?? ''));

            if ($playlistId < 0) {
                return $this->jsonResponse(['success' => false, 'error' => 'Invalid playlist_id.'], 400);
            }

            $this->splayService->playPlaylist($device, $playlistId, $playlistUuid, $label);
            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    public function blackout(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $device = $this->resolveDevice((int) ($data['device_id'] ?? 0));
            $this->splayService->triggerBlackout($device);
            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function recordStatic(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $device = $this->resolveDevice((int) ($data['device_id'] ?? 0));
            $name = trim((string) ($data['name'] ?? 'New Scene'));
            if ($name === '') {
                $name = 'New Scene';
            }

            $this->splayService->recordStaticScene(
                $device,
                (int) $data['playlist_id'],
                (int) $data['playlist_uuid'],
                (int) $data['cue_id'],
                (int) $data['cue_uuid'],
                $name,
                (int) ($data['source_type'] ?? 0),
                is_array($data['universes'] ?? null) ? $data['universes'] : [0]
            );

            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function armDynamic(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $device = $this->resolveDevice((int) ($data['device_id'] ?? 0));

            $this->splayService->armDynamicRecording(
                $device,
                (int) $data['cue_id'],
                (int) $data['cue_uuid'],
                (int) ($data['source_type'] ?? 0),
                is_array($data['universes'] ?? null) ? $data['universes'] : [0]
            );

            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function stopDynamic(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $device = $this->resolveDevice((int) ($data['device_id'] ?? 0));
            $name = trim((string) ($data['name'] ?? 'Dynamic Scene'));

            $this->splayService->stopAndSaveDynamicScene(
                $device,
                (int) $data['playlist_id'],
                (int) $data['playlist_uuid'],
                (int) $data['cue_id'],
                (int) $data['cue_uuid'],
                $name !== '' ? $name : 'Dynamic Scene',
                (int) ($data['source_type'] ?? 0),
                is_array($data['universes'] ?? null) ? $data['universes'] : [0],
                (int) ($data['duration_ms'] ?? 5000)
            );

            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deletePlaylist(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $device = $this->resolveDevice((int) ($data['device_id'] ?? 0));
            $playlistId = (int) ($data['playlist_id'] ?? -1);
            $cueId = (int) ($data['cue_id'] ?? -1);

            $this->splayService->deletePlaylist($device, $playlistId, $cueId);
            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function CanAccessMatrix(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_MATRIX);
    }

    public function CanAccessAudio(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX);
    }

    public function CanAccessLighting(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC);
    }

    public function CanAccessDiscovery(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_DISCOVERY);
    }

    public function CurrentActorName(): string
    {
        return VPXSecurityGuard::getActorLabel($this->getRequest());
    }

    private function resolveDevice(int $id): EnttecDevice
    {
        $device = $id > 0 ? EnttecDevice::get()->byID($id) : EnttecDevice::get()->first();
        if (!$device) {
            throw new \RuntimeException('No Enttec S-Play controller configured in AV System admin.');
        }
        return $device;
    }

    private function parseRequestData(HTTPRequest $request): array
    {
        $body = $request->getBody();
        if ($body !== '' && str_contains((string) $request->getHeader('Content-Type'), 'application/json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $request->postVars();
    }

    private function jsonResponse(array $payload, int $statusCode = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_PRETTY_PRINT))
            ->setStatusCode($statusCode)
            ->addHeader('Content-Type', 'application/json');
    }
}
