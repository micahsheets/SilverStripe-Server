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
        ''          => 'index',
        'api'       => 'api',
        'discover'  => 'discover',
        'setip'     => 'setip',
        'import'    => 'import',
    ];

    private static array $allowed_actions = [
        'index',
        'api',
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
            $aCat = 'Camera_Control'; $aTitle = "Camera Preset {$command} on {$camera->Title}"; $aDet = "Cmd: {$command}, Param: {$param}";
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

            $aCat = 'Camera_Network'; $aTitle = "PTZOptics IP updated ({$currentIp} -> {$result['newIp']})"; $aDet = implode(', ', $result['methods']); $aOk = true; $aErr = '';
            $this->extend('onPanelAudit', $aCat, $aTitle, $aDet, $aOk, $aErr);

            return $this->jsonResponse($result);
        } catch (Throwable $e) {
            $aCat = 'Camera_Network'; $aTitle = 'PTZOptics IP update failed'; $aDet = ''; $aOk = false; $aErr = $e->getMessage();
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
                $cam->Title = trim((string) ($item['title'] ?? $item['hostname'] ?? "PTZOptics ({$ip})"));
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
