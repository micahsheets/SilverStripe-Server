<?php

namespace App\Controller;

use App\Model\Location;
use App\Model\RoutePreset;
use App\Model\VPXDevice;
use App\Security\VPXSecurityGuard;
use App\Service\VPXUDPService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\SecurityToken;
use Throwable;
use App\Service\VPXDiscoveryService;

class UDPController extends Controller
{
    private static string $url_segment = 'api/vpx';

    private static array $allowed_actions = ['status', 'route', 'preset', 'command', 'ping', 'discover', 'import'];

    private VPXUDPService $udpService;

    public function __construct()
    {
        parent::__construct();
        $this->udpService = VPXUDPService::singleton();
    }

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();

        if (!VPXSecurityGuard::isIPAllowed($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'IP not allowed.'], 403));
        }
        if (!VPXSecurityGuard::isAuthenticated($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Auth required.'], 401));
        }
        if ($request->isPOST() && !VPXSecurityGuard::isValidCSRF($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403));
        }
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $locations = array_map(fn(Location $l) => [
            'id' => (int) $l->ID, 'title' => $l->Title, 'description' => $l->Description,
        ], Location::get()->toArray());

        $encoders = array_map(fn(VPXDevice $e) => [
            'id' => (int) $e->ID, 'title' => $e->Title, 'hostname' => $e->Hostname,
            'ip' => $e->IPAddress, 'locationId' => (int) $e->LocationID,
        ], VPXDevice::get()->filter('Mode', VPXDevice::MODE_ENCODER)->toArray());

        $decoders = array_map(fn(VPXDevice $d) => [
            'id' => (int) $d->ID,
            'title' => $d->Title,
            'hostname' => $d->Hostname,
            'ip' => $d->IPAddress,
            'locationId' => (int) $d->LocationID,
            'videoEncoderId' => (int) $d->SubscribedVideoEncoderID,
            'audioEncoderId' => (int) $d->SubscribedAudioEncoderID,
            'usbEncoderId' => (int) $d->SubscribedUSBEncoderID,
            'powerOnCommand' => $d->PowerOnCommand ?: 'cec power on',
            'powerOffCommand' => $d->PowerOffCommand ?: 'cec power off',
        ], VPXDevice::get()->filter('Mode', VPXDevice::MODE_DECODER)->toArray());


        $presets = array_map(fn(RoutePreset $p) => [
            'id' => (int) $p->ID, 'title' => $p->Title, 'description' => $p->Description,
            'locationId' => (int) $p->LocationID,
        ], RoutePreset::get()->toArray());

        return $this->jsonResponse([
            'success' => true,
            'csrfToken' => SecurityToken::inst()->getValue(),
            'locations' => $locations,
            'encoders' => $encoders,
            'decoders' => $decoders,
            'presets' => $presets,
        ]);
    }

    public function route(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $decoder = VPXDevice::get()->byID((int) ($data['decoder_id'] ?? 0));
            if (!$decoder) {
                return $this->jsonResponse(['success' => false, 'error' => 'Decoder not found.'], 404);
            }
            $encoderId = (int) ($data['encoder_id'] ?? 0);
            $encoder = $encoderId > 0 ? VPXDevice::get()->byID($encoderId) : null;
            if ($encoderId > 0 && !$encoder) {
                return $this->jsonResponse(['success' => false, 'error' => 'Encoder not found.'], 404);
            }

            $result = $this->udpService->route($decoder, $encoder, (string) ($data['stream_type'] ?? 'all'));
            return $this->jsonResponse(['success' => true, 'route' => $result]);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 500);
        }
    }

    public function preset(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $preset = RoutePreset::get()->byID((int) ($data['preset_id'] ?? 0));
            if (!$preset) {
                return $this->jsonResponse(['success' => false, 'error' => 'RoutePreset not found.'], 404);
            }
            $outcome = $this->udpService->executePreset($preset);
            $hasErrors = count($outcome['errors']) > 0;
            return $this->jsonResponse(['success' => !$hasErrors, 'result' => $outcome], $hasErrors ? 207 : 200);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 500);
        }
    }

    public function command(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $device = VPXDevice::get()->byID((int) ($data['device_id'] ?? 0));
            $rawCommand = trim((string) ($data['command'] ?? ''));
            if (!$device) {
                return $this->jsonResponse(['success' => false, 'error' => 'Device not found.'], 404);
            }
            if ($rawCommand === '') {
                return $this->jsonResponse(['success' => false, 'error' => 'Command cannot be empty.'], 400);
            }

            $this->udpService->sendCommand($device, $rawCommand);
            return $this->jsonResponse(['success' => true, 'device' => $device->Title, 'commandSent' => $rawCommand]);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 500);
        }
    }

    private function parseRequestData(HTTPRequest $request): array
    {
        $body = $request->getBody();
        if ($body !== '' && str_contains((string) $request->getHeader('Content-Type'), 'application/json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) return $decoded;
        }
        return $request->postVars();
    }

    private function jsonResponse(array $payload, int $statusCode = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_PRETTY_PRINT))
            ->setStatusCode($statusCode)
            ->addHeader('Content-Type', 'application/json');
    }

    public function ping(HTTPRequest $request): HTTPResponse
    {
        $statuses = [];
        foreach (VPXDevice::get() as $device) {
            $statuses[(int) $device->ID] = $this->udpService->isDeviceOnline($device);
        }

        return $this->jsonResponse([
            'success' => true,
            'online' => $statuses,
        ]);
    }

    public function discover(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $subnet = trim((string) ($data['subnet'] ?? ''));
            $devices = VPXDiscoveryService::singleton()->scan($subnet);

            return $this->jsonResponse([
                'success' => true,
                'devices' => $devices,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function import(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseRequestData($request);
            $items = $data['devices'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                return $this->jsonResponse(['success' => false, 'error' => 'No devices selected for import.'], 400);
            }

            $created = 0;
            $updated = 0;

            foreach ($items as $item) {
                $ip = trim((string) ($item['ip'] ?? ''));
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }

                $device = VPXDevice::get()->filter('IPAddress', $ip)->first();
                $isNew = !$device;
                if ($isNew) {
                    $device = VPXDevice::create();
                    $device->IPAddress = $ip;
                }

                $device->Title = trim((string) ($item['title'] ?? $ip));
                $device->Hostname = trim((string) ($item['hostname'] ?? $device->Title));
                $device->Mode = ($item['mode'] ?? '') === VPXDevice::MODE_ENCODER
                    ? VPXDevice::MODE_ENCODER
                    : VPXDevice::MODE_DECODER;
                $device->UDPPort = (int) ($item['udpPort'] ?? 6970);
                $device->LocationID = (int) ($item['locationId'] ?? 0);
                if (!empty($item['mac'])) {
                    $device->MACAddress = trim((string) $item['mac']);
                }
                $device->write();

                $isNew ? $created++ : $updated++;
            }

            return $this->jsonResponse([
                'success' => true,
                'created' => $created,
                'updated' => $updated,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }


}
