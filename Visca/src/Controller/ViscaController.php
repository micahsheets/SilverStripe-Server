<?php

namespace App\Visca\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Visca\Model\ViscaCamera;
use App\Security\PanelSecurityGuard;
use App\Visca\Service\ViscaService;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\PermissionProvider;

class ViscaController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    public const PERM_ACCESS = 'PANEL_ACCESS_CAMERA';

    private static string $url_segment = 'panel/camera';

    private static array $url_handlers = [
        '' => 'index',
        'api' => 'api',
    ];

    private static array $allowed_actions = [
        'index',
        'api',
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
                'name' => 'Access VISCA Camera Control',
                'category' => 'AV & Control System',
                'help' => 'Allows controlling PTZ cameras and recalling/saving presets.',
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
            'PanelSettings' => PanelSettings::current(),
            'Cameras' => ViscaCamera::get()->filter('Active', 1),
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

        $deviceId = $request->postVar('device_id');
        $command = $request->postVar('command');
        $param = $request->postVar('param');

        $camera = ViscaCamera::get()->byID($deviceId);
        if (!$camera) {
            return $this->httpError(404, 'Camera not found');
        }

        $service = new ViscaService($camera->IPAddress, $camera->Port);
        $success = false;
        $errorDetail = '';

        try {
            switch ($command) {
                case 'move':
                    $success = $service->ptzMove($param);
                    break;
                case 'zoom':
                    $success = $service->ptzZoom($param);
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
            $auditArgs = ['Camera_Control',
                "Camera Preset {$command} on {$camera->Title}",
                "Cmd: {$command}, Param: {$param}",
                $success,
                $errorDetail];
        $this->extend('onPanelAudit', ...$auditArgs);
        }

        if (!$success) {
            return json_encode([
                'status' => 'error',
                'message' => $errorDetail,
            ]);
        }

        return json_encode([
            'status' => 'success',
        ]);
    }
}
