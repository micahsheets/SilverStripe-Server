<?php

namespace App\Controller;

use App\Model\Location;
use App\Model\RoutePreset;
use App\Model\VPXDevice;
use App\Security\VPXPermissionProvider;
use App\Security\VPXSecurityGuard;
use App\Service\VPXDiscoveryService;
use App\Service\VPXUDPService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\SecurityToken;
use Throwable;


class UDPController extends Controller
{
    private static string $url_segment = 'api/vpx';

    private static array $allowed_actions = ['status', 'route', 'preset', 'command', 'ping', 'discover', 'import', 'preview'];

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

        $action = strtolower((string) $request->param('Action'));
        if ($action === 'preview') {
            return;
        }

        if (!VPXSecurityGuard::isAuthenticated($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Auth required.'], 401));
        }
        if ($request->isPOST() && !VPXSecurityGuard::isValidCSRF($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403));
        }

        // Enforce action-specific interface permissions
        $requiredPermission = in_array($action, ['discover', 'import'], true)
            ? VPXPermissionProvider::PERM_ACCESS_DISCOVERY
            : VPXPermissionProvider::PERM_ACCESS_MATRIX;

        if (!VPXSecurityGuard::canAccess($request, $requiredPermission)) {
            throw new HTTPResponse_Exception(
                $this->jsonResponse(['success' => false, 'error' => 'Permission denied for this operation.'], 403)
            );
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
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 500);
        }
    }

public function import(HTTPRequest $request): HTTPResponse
{
    if (!$request->isPOST()) {
        return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
    }

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
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $device = VPXDevice::get()->filter('IPAddress', $ip)->first();
            $isNew = !$device;
            if ($isNew) {
                $device = VPXDevice::create();
                $device->IPAddress = $ip;
            }

            $hostname = trim((string) ($item['hostname'] ?? ''));
            if ($hostname === '') {
                $hostname = 'VPX-' . str_replace('.', '-', $ip);
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $title = $hostname;
            }

            $mode = (string) ($item['mode'] ?? VPXDevice::MODE_DECODER);
            if (!in_array($mode, [VPXDevice::MODE_ENCODER, VPXDevice::MODE_DECODER], true)) {
                $mode = VPXDevice::MODE_DECODER;
            }

            $device->Title = $title;
            $device->Hostname = $hostname;
            $device->Mode = $mode;
            $device->UDPPort = (int) ($item['udpPort'] ?? 6970) ?: 6970;
            $device->MACAddress = trim((string) ($item['mac'] ?? ''));
            $device->LocationID = (int) ($item['locationId'] ?? 0);

            $device->write();

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
        ]);
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

    public function preview(HTTPRequest $request): HTTPResponse
    {
        $device = VPXDevice::get()->byID((int) $request->param('ID'));
        if (!$device || !filter_var($device->IPAddress, FILTER_VALIDATE_IP)) {
            return HTTPResponse::create('', 404);
        }

        $ip = trim((string) $device->IPAddress);
        $port = (int) (getenv('VPX_PREVIEW_PORT') ?: 8080);

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

private function fetchDeviceSnapshot(string $ip, string $uri, string $user, string $pass): ?string
{
    $fp = @fsockopen($ip, 80, $errno, $errstr, 0.4);
    if (!is_resource($fp)) {
        return null;
    }
    stream_set_timeout($fp, 0, 500_000);
    fwrite($fp, "GET {$uri} HTTP/1.0\r\nHost: {$ip}\r\n\r\n");
    $raw = stream_get_contents($fp, 262144);
    fclose($fp);

    if (!$raw) {
        return null;
    }

    // Unauthenticated 200 OK
    if (preg_match('/^HTTP\/1\.[01]\s+200\b/i', $raw) && str_contains($raw, "\r\n\r\n")) {
        [, $body] = explode("\r\n\r\n", $raw, 2);
        return strlen($body) > 100 ? $body : null;
    }

    // HTTP Digest challenge
    if (!preg_match('/WWW-Authenticate:\s*Digest\s+([^\r\n]+)/i', $raw, $m)) {
        return null;
    }

    preg_match_all('/(\w+)="([^"]+)"/', $m[1], $pairs, PREG_SET_ORDER);
    $params = array_column($pairs, 2, 1);
    if (empty($params['realm']) || empty($params['nonce'])) {
        return null;
    }

    $nc = '00000001';
    $cnonce = bin2hex(random_bytes(8));
    $qop = $params['qop'] ?? 'auth';
    $ha1 = md5("{$user}:{$params['realm']}:{$pass}");
    $ha2 = md5("GET:{$uri}");
    $responseHash = md5("{$ha1}:{$params['nonce']}:{$nc}:{$cnonce}:{$qop}:{$ha2}");

    $authHeader = sprintf(
        'Digest username="%s", realm="%s", nonce="%s", uri="%s", qop=%s, nc=%s, cnonce="%s", response="%s"',
        $user,
        $params['realm'],
        $params['nonce'],
        $uri,
        $qop,
        $nc,
        $cnonce,
        $responseHash
    );

    $fp2 = @fsockopen($ip, 80, $errno, $errstr, 0.4);
    if (!is_resource($fp2)) {
        return null;
    }
    stream_set_timeout($fp2, 0, 600_000);
    fwrite($fp2, "GET {$uri} HTTP/1.0\r\nHost: {$ip}\r\nAuthorization: {$authHeader}\r\n\r\n");
    $authedRaw = stream_get_contents($fp2, 262144);
    fclose($fp2);

    if ($authedRaw && preg_match('/^HTTP\/1\.[01]\s+200\b/i', $authedRaw) && str_contains($authedRaw, "\r\n\r\n")) {
        [, $body] = explode("\r\n\r\n", $authedRaw, 2);
        return strlen($body) > 100 ? $body : null;
    }

    return null;
}
}
