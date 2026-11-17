<?php

namespace App\Visca\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Security\PanelPermissionProvider;
use App\Security\PanelSecurityGuard;
use App\Traits\TouchPanelControllerTrait;
use App\Visca\Model\ViscaCamera;
use App\Visca\Service\PtzOpticsDiscoveryService;
use App\Visca\Service\ViscaService;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\PermissionProvider;
use Throwable;

class ViscaController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    use TouchPanelControllerTrait;

    public const PERM_ACCESS = 'PANEL_ACCESS_CAMERA';

    private static string $url_segment = 'panel/camera';

    private static array $url_handlers = [
        ''         => 'index',
        'api'      => 'api',
        'snapshot' => 'snapshot',
        'discover' => 'discover',
        'setip'    => 'setip',
        'import'   => 'import',
    ];

    private static array $allowed_actions = [
        'index',
        'api',
        'snapshot',
        'discover',
        'setip',
        'import',
    ];

    public static function getModuleTitle(): string
    {
        return 'Cameras';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/camera';
    }

    public static function getModulePermission(): string
    {
        return self::PERM_ACCESS;
    }

    public static function getModuleSortOrder(): int
    {
        return 40;
    }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name'     => 'Access VISCA Camera Control',
                'category' => 'AV & Control System',
                'help'     => 'Allows controlling PTZ cameras and recalling/saving presets.',
            ],
        ];
    }

    public function init()
    {
        parent::init();

        if ($this->getRequest()->isPOST()) {
            if (!PanelSecurityGuard::isValidCSRF($this->getRequest())) {
                return $this->httpError(403, 'Invalid CSRF token');
            }
        }
    }

    public function index(HTTPRequest $request)
    {
        $session = $request->getSession();

        if (!PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            $session->save($request);
            return $this->redirect('/panel/splash');
        }

        $session->save($request);

        return $this->customise([
            'PanelSettings'      => PanelSettings::current(),
            'Cameras'            => ViscaCamera::get()->filter('Active', 1),
            'CanAccessDiscovery' => PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY),
        ])->renderWith([
            'App\\Visca\\Controller\\TouchPanelCamera',
            'Page',
        ]);
    }

    /**
     * Server-side JPEG snapshot proxy for PTZOptics, AIDA Imaging, and ONVIF cameras.
     * Avoids browser CORS / mixed-content / Basic-Digest auth prompts on touch panels.
     */
    public function snapshot(HTTPRequest $request): HTTPResponse
    {
        if (!PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            return HTTPResponse::create('Forbidden', 403);
        }

        $cameraId = (int) $request->getVar('id');
        $camera = ViscaCamera::get()->byID($cameraId);
        if (!$camera || !$camera->Active) {
            return HTTPResponse::create('Camera not found', 404);
        }

        $ip = trim((string) $camera->IPAddress);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return HTTPResponse::create('Invalid camera IP', 400);
        }

        $cacheFile = sys_get_temp_dir() . '/cam_snap_uri_' . md5($ip) . '.txt';
        $cachedUri = is_file($cacheFile) ? trim((string) @file_get_contents($cacheFile)) : '';

        $candidates = array_values(array_unique(array_filter([
            $cachedUri,
            "http://{$ip}/snapshot.jpg",
            "http://{$ip}/cgi-bin/snapshot.cgi",
            "http://{$ip}/jpg/image.jpg",
            "http://{$ip}/cgi-bin/param.cgi?get_snapshot",
            "http://{$ip}/snap.jpg",
        ])));

        foreach ($candidates as $url) {
            $jpeg = $this->fetchJpegFromUrl($url, 'admin', 'admin');
            if ($jpeg !== null) {
                if ($cachedUri !== $url) {
                    @file_put_contents($cacheFile, $url);
                }
                return HTTPResponse::create($jpeg, 200)
                    ->addHeader('Content-Type', 'image/jpeg')
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            }
        }

        // Fallback: resolve SnapshotUri dynamically via ONVIF Media GetProfiles + GetSnapshotUri
        $onvifUri = $this->resolveOnvifSnapshotUri($ip);
        if ($onvifUri !== '') {
            $jpeg = $this->fetchJpegFromUrl($onvifUri, 'admin', 'admin');
            if ($jpeg !== null) {
                @file_put_contents($cacheFile, $onvifUri);
                return HTTPResponse::create($jpeg, 200)
                    ->addHeader('Content-Type', 'image/jpeg')
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            }
        }

        if ($cachedUri !== '' && is_file($cacheFile)) {
            @unlink($cacheFile);
        }

        return HTTPResponse::create('Snapshot unavailable from camera', 502)
            ->addHeader('Cache-Control', 'no-store');
    }

    private function fetchJpegFromUrl(string $url, string $user, string $pass): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_ANY,
            CURLOPT_USERPWD        => "{$user}:{$pass}",
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Verify HTTP 200 and JPEG SOI marker (FF D8)
        if ($code === 200 && is_string($body) && strlen($body) > 256 && strncmp($body, "\xFF\xD8", 2) === 0) {
            return $body;
        }
        return null;
    }

    private function resolveOnvifSnapshotUri(string $ip): string
    {
        $profilesXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl">'
            . '<s:Body><trt:GetProfiles/></s:Body></s:Envelope>';

        $ch = curl_init("http://{$ip}/onvif/media_service");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $profilesXml,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8'],
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
        ]);
        $resp = (string) curl_exec($ch);
        curl_close($ch);

        if (!preg_match('/<[^:>]*:?Profiles[^>]*\btoken="([^"]+)"/i', $resp, $pm)) {
            return '';
        }
        $profileToken = htmlspecialchars($pm[1], ENT_XML1);

        $snapXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl">'
            . "<s:Body><trt:GetSnapshotUri><trt:ProfileToken>{$profileToken}</trt:ProfileToken></trt:GetSnapshotUri></s:Body></s:Envelope>";

        $ch2 = curl_init("http://{$ip}/onvif/media_service");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $snapXml,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8'],
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
        ]);
        $resp2 = (string) curl_exec($ch2);
        curl_close($ch2);

        if (preg_match('/<[^:>]*:?Uri[^>]*>([^<]+)</i', $resp2, $um)) {
            return html_entity_decode(trim($um[1]), ENT_QUOTES | ENT_XML1);
        }
        return '';
    }

    public function api(HTTPRequest $request)
    {
        if (!$request->isPOST()) {
            return $this->httpError(405, 'Method Not Allowed');
        }

        if (!PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            return $this->httpError(403, 'Forbidden');
        }

        $data = $this->parseRequestData($request);
        $deviceId = $data['device_id'] ?? $request->postVar('device_id');
        $command = $data['command'] ?? $request->postVar('command');
        $param = $data['param'] ?? $request->postVar('param');

        $camera = ViscaCamera::get()->byID($deviceId);
        if (!$camera) {
            return $this->httpError(404, 'Camera not found');
        }

        $service = new ViscaService($camera->IPAddress, (int) ($camera->Port ?: 1259));
        $success = false;
        $errorDetail = '';

        try {
            switch ($command) {
                case 'move':
                    $success = $service->ptzMove((string) $param);
                    break;
                case 'zoom':
                    $success = $service->ptzZoom((string) $param);
                    break;
                case 'recall':
                    $success = $service->recallPreset((int) $param);
                    break;
                case 'save':
                    $success = $service->savePreset((int) $param);
                    break;
                default:
                    throw new Exception("Invalid camera command: {$command}");
            }
        } catch (Exception $e) {
            $success = false;
            $errorDetail = $e->getMessage();
        }

        if (in_array($command, ['save', 'recall'], true)) {
            $aCat = 'Camera_Control';
            $aTitle = "Camera Preset {$command} on {$camera->Title}";
            $aDet = "Cmd: {$command}, Param: {$param}";
            $this->extend('onPanelAudit', $aCat, $aTitle, $aDet, $success, $errorDetail);
        }

        return $this->jsonResponse([
            'status'  => $success ? 'success' : 'error',
            'success' => $success,
            'message' => $errorDetail,
        ], $success ? 200 : 500);
    }

    public function discover(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        if (!PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        try {
            $data = $this->parseRequestData($request);
            $subnet = trim((string) ($data['subnet'] ?? ''));
            $cameras = PtzOpticsDiscoveryService::singleton()->scan($subnet);

            return $this->jsonResponse([
                'success' => true,
                'cameras' => $cameras,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function setip(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        if (!PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        try {
            $data = $this->parseRequestData($request);
            $currentIp = trim((string) ($data['currentIp'] ?? ''));
            $newIp = trim((string) ($data['newIp'] ?? ''));
            $netmask = trim((string) ($data['netmask'] ?? '255.255.255.0')) ?: '255.255.255.0';
            $gateway = trim((string) ($data['gateway'] ?? ''));
            $dhcp = filter_var($data['dhcp'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $username = trim((string) ($data['username'] ?? 'admin')) ?: 'admin';
            $password = (string) ($data['password'] ?? 'admin');

            $result = PtzOpticsDiscoveryService::singleton()->updateCameraNetwork(
                $currentIp,
                $newIp,
                $netmask,
                $gateway,
                $dhcp,
                $username,
                $password
            );

            $aCat = 'Camera_Network';
            $aTitle = "Camera IP updated ({$currentIp} -> {$result['newIp']})";
            $aDet = implode(', ', $result['methods']);
            $aOk = true;
            $aErr = '';
            $this->extend('onPanelAudit', $aCat, $aTitle, $aDet, $aOk, $aErr);

            return $this->jsonResponse($result);
        } catch (Throwable $e) {
            $aCat = 'Camera_Network';
            $aTitle = 'Camera IP update failed';
            $aDet = '';
            $aOk = false;
            $aErr = $e->getMessage();
            $this->extend('onPanelAudit', $aCat, $aTitle, $aDet, $aOk, $aErr);
            return $this->jsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function import(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        if (!PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        try {
            $data = $this->parseRequestData($request);
            $items = $data['cameras'] ?? [];
            $imported = 0;

            foreach ($items as $item) {
                $ip = trim((string) ($item['ip'] ?? ''));
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }
                $cam = ViscaCamera::get()->filter('IPAddress', $ip)->first() ?: ViscaCamera::create();
                $cam->Title = trim((string) ($item['title'] ?? $item['hostname'] ?? "Camera ({$ip})"));
                $cam->IPAddress = $ip;
                $cam->Port = (int) ($item['port'] ?? 1259);
                if (isset($item['locationId']) && (int) $item['locationId'] > 0) {
                    $cam->LocationID = (int) $item['locationId'];
                }
                $cam->Active = 1;
                $cam->write();
                $imported++;
            }

            return $this->jsonResponse([
                'success'  => true,
                'imported' => $imported,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
