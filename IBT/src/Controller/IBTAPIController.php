<?php

namespace App\IBT\Controller;

use App\IBT\Model\IBTDevice;
use App\IBT\Model\IBTRoutePreset;
use App\IBT\Model\IBTSerialCommand;
use App\IBT\Service\IBTControlService;
use App\IBT\Service\IBTDiscoveryService;
use App\Model\Location;
use App\Security\PanelPermissionProvider;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\SecurityToken;
use Throwable;

class IBTAPIController extends Controller
{
    private static string $url_segment = 'api/ibt';

    private static array $allowed_actions = ['status', 'route', 'preset', 'command', 'ping', 'discover', 'import', 'preview'];

    private IBTControlService $controlService;

    public function __construct()
    {
        parent::__construct();
        $this->controlService = IBTControlService::singleton();
    }

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();

        if (!PanelSecurityGuard::isIPAllowed($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'IP not allowed.'], 403));
        }

        $action = strtolower((string) $request->param('Action'));
        if ($action === 'preview') {
            return;
        }

        if (!PanelSecurityGuard::isAuthenticated($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Auth required.'], 401));
        }
        if ($request->isPOST() && !PanelSecurityGuard::isValidCSRF($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403));
        }

        $requiredPermission = in_array($action, ['discover', 'import'], true)
            ? PanelPermissionProvider::PERM_ACCESS_DISCOVERY
            : IBTController::PERM_ACCESS;

        if (!PanelSecurityGuard::canAccess($request, $requiredPermission)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403));
        }
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $locations = array_map(fn(Location $l) => [
            'id' => (int) $l->ID, 'title' => $l->Title, 'description' => $l->Description,
        ], Location::get()->toArray());

        $mapSerial = fn(IBTDevice $dev) => array_map(fn(IBTSerialCommand $cmd) => [
            'id' => (int) $cmd->ID, 'title' => $cmd->Title, 'command' => $cmd->Command,
        ], $dev->SerialCommands()->toArray());

        $encoders = array_map(fn(IBTDevice $e) => [
            'id' => (int) $e->ID, 'title' => $e->Title, 'hostname' => $e->Hostname,
            'ip' => $e->IPAddress, 'channelNumber' => (int) $e->ChannelNumber,
            'locationId' => (int) $e->LocationID, 'serialCommands' => $mapSerial($e),
        ], IBTDevice::get()->filter('Mode', IBTDevice::MODE_ENCODER)->toArray());

        $decoders = array_map(fn(IBTDevice $d) => [
            'id' => (int) $d->ID, 'title' => $d->Title, 'hostname' => $d->Hostname,
            'ip' => $d->IPAddress, 'locationId' => (int) $d->LocationID,
            'videoEncoderId' => (int) $d->SubscribedVideoEncoderID,
            'audioEncoderId' => (int) $d->SubscribedAudioEncoderID,
            'usbEncoderId'   => (int) $d->SubscribedUSBEncoderID,
            'powerOnCommand'  => $d->PowerOnCommand ?: 'cec power on',
            'powerOffCommand' => $d->PowerOffCommand ?: 'cec power off',
            'serialCommands'  => $mapSerial($d),
        ], IBTDevice::get()->filter('Mode', IBTDevice::MODE_DECODER)->toArray());

        $presets = array_map(fn(IBTRoutePreset $p) => [
            'id' => (int) $p->ID, 'title' => $p->Title, 'description' => $p->Description,
            'locationId' => (int) $p->LocationID,
        ], IBTRoutePreset::get()->toArray());

        return $this->jsonResponse([
            'success'   => true,
            'csrfToken' => SecurityToken::inst()->getValue(),
            'locations' => $locations,
            'encoders'  => $encoders,
            'decoders'  => $decoders,
            'presets'   => $presets,
        ]);
    }

    public function route(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        try {
            $data = $this->parseRequestData($request);
            $decoder = IBTDevice::get()->byID((int) ($data['decoder_id'] ?? 0));
            if (!$decoder) return $this->jsonResponse(['success' => false, 'error' => 'Receiver not found.'], 404);
            $encoderId = (int) ($data['encoder_id'] ?? 0);
            $encoder = $encoderId > 0 ? IBTDevice::get()->byID($encoderId) : null;
            if ($encoderId > 0 && !$encoder) return $this->jsonResponse(['success' => false, 'error' => 'Transmitter not found.'], 404);
            $result = $this->controlService->route($decoder, $encoder, (string) ($data['stream_type'] ?? 'all'));
            return $this->jsonResponse(['success' => true, 'route' => $result]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function preset(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        try {
            $data = $this->parseRequestData($request);
            $preset = IBTRoutePreset::get()->byID((int) ($data['preset_id'] ?? 0));
            if (!$preset) return $this->jsonResponse(['success' => false, 'error' => 'IBTRoutePreset not found.'], 404);
            $outcome = $this->controlService->executePreset($preset);
            $hasErrors = count($outcome['errors']) > 0;
            return $this->jsonResponse(['success' => !$hasErrors, 'result' => $outcome], $hasErrors ? 207 : 200);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function command(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        try {
            $data = $this->parseRequestData($request);
            $device = IBTDevice::get()->byID((int) ($data['device_id'] ?? 0));
            $rawCommand = trim((string) ($data['command'] ?? ''));
            if (!$device) return $this->jsonResponse(['success' => false, 'error' => 'Device not found.'], 404);
            if ($rawCommand === '') return $this->jsonResponse(['success' => false, 'error' => 'Command cannot be empty.'], 400);
            $this->controlService->sendCommand($device, $rawCommand);
            return $this->jsonResponse(['success' => true, 'device' => $device->Title, 'commandSent' => $rawCommand]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function ping(HTTPRequest $request): HTTPResponse
    {
        $statuses = [];
        foreach (IBTDevice::get() as $device) {
            $statuses[(int) $device->ID] = $this->controlService->isDeviceOnline($device);
        }
        return $this->jsonResponse(['success' => true, 'online' => $statuses]);
    }

    public function discover(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        try {
            $data = $this->parseRequestData($request);
            $devices = IBTDiscoveryService::singleton()->scan(trim((string) ($data['subnet'] ?? '')));
            return $this->jsonResponse(['success' => true, 'devices' => $devices]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function import(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        try {
            $data = $this->parseRequestData($request);
            $devices = $data['devices'] ?? [];
            if (!is_array($devices) || count($devices) === 0) {
                return $this->jsonResponse(['success' => false, 'error' => 'No devices provided.'], 400);
            }
            $created = 0;
            $updated = 0;
            foreach ($devices as $item) {
                $ip = trim((string) ($item['ip'] ?? ''));
                if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
                $device = IBTDevice::get()->filter('IPAddress', $ip)->first();
                $isNew = !$device;
                if ($isNew) $device = IBTDevice::create(['IPAddress' => $ip]);
                $device->Title = trim((string) ($item['title'] ?? $ip));
                $device->Hostname = trim((string) ($item['hostname'] ?? $device->Title));
                $device->Mode = ($item['mode'] ?? '') === IBTDevice::MODE_ENCODER ? IBTDevice::MODE_ENCODER : IBTDevice::MODE_DECODER;
                $device->ControlPort = (int) ($item['controlPort'] ?? 6970);
                $device->MACAddress = trim((string) ($item['mac'] ?? ''));
                $device->LocationID = (int) ($item['locationId'] ?? 0);
                $device->write();
                $isNew ? $created++ : $updated++;
            }
            return $this->jsonResponse(['success' => true, 'created' => $created, 'updated' => $updated]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function preview(HTTPRequest $request): HTTPResponse
    {
        $device = IBTDevice::get()->byID((int) $request->param('ID'));
        if (!$device || !filter_var($device->IPAddress, FILTER_VALIDATE_IP)) {
            return HTTPResponse::create('', 404);
        }

        $ip = trim((string) $device->IPAddress);
        $port = (int) (getenv('IBT_PREVIEW_PORT') ?: getenv('VPX_PREVIEW_PORT') ?: 8080);

        $fp = @fsockopen($ip, $port, $errno, $errstr, 0.4);
        if (!is_resource($fp)) {
            return HTTPResponse::create('', 204);
        }

        stream_set_timeout($fp, 0, 500_000);
        fwrite($fp, "GET /?action=snapshot HTTP/1.0\r\nHost: {$ip}:{$port}\r\n\r\n");
        $raw = stream_get_contents($fp, 524288);
        fclose($fp);

        if (!$raw || !preg_match('/^HTTP\/1\.[01]\s+200\b/i', $raw) || !str_contains($raw, "\r\n\r\n")) {
            return HTTPResponse::create('', 204);
        }

        [, $jpeg] = explode("\r\n\r\n", $raw, 2);
        if (!str_starts_with($jpeg, "\xFF\xD8")) {
            return HTTPResponse::create('', 204);
        }

        return HTTPResponse::create($jpeg, 200)
            ->addHeader('Content-Type', 'image/jpeg')
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
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
