<?php

namespace App\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use App\Security\VPXSecurityGuard;
use App\Model\RouteAuditLog;
use App\Model\ViscaCamera;
use App\Service\ViscaService;
use Exception;

class ViscaController extends Controller
{
    private static $url_segment = 'panel/camera';

    private static $url_handlers = array(
        '' => 'index',
        'api' => 'api'
    );

    private static $allowed_actions = array(
        'index',
        'api'
    );

    public function init()
    {
        parent::init();

        if ($this->getRequest()->isPOST()) {
            if (!VPXSecurityGuard::isValidCSRF($this->getRequest())) {
                return $this->httpError(403, 'Invalid CSRF token');
            }
        }
    }

    public function index(HTTPRequest $request)
    {
        $session = $request->getSession();

        // Check both Member permissions and Basic PIN permissions
        if (!VPXSecurityGuard::canAccess($request, 'VPX_ACCESS_CAMERA')) {
            $session->save($request);
            return $this->redirect('/panel/splash');
        }

        $session->save($request);

        return $this->customise(array(
            'PanelSettings' => \App\Model\PanelSettings::current(),
            'Cameras' => ViscaCamera::get()->filter('Active', 1)
        ))->renderWith(array(
            'App\\Controller\\TouchPanelCamera',
            'Page'
        ));
    }

    public function api(HTTPRequest $request)
    {
        if (!$request->isPOST()) {
            return $this->httpError(405, 'Method Not Allowed');
        }

        if (!VPXSecurityGuard::canAccess($request, 'VPX_ACCESS_CAMERA')) {
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
                    $success = $service->recallPreset((int)$param);
                    break;
                case 'save':
                    $success = $service->savePreset((int)$param);
                    break;
                default:
                    throw new Exception("Invalid camera command: {$command}");
            }
        } catch (Exception $e) {
            $success = false;
            $errorDetail = $e->getMessage();
        }

        if (in_array($command, array('save', 'recall'))) {
            RouteAuditLog::record(
                'Camera_Control',
                "Camera Preset {$command} on {$camera->Title}",
                "Cmd: {$command}, Param: {$param}",
                $success,
                $errorDetail
            );
        }

        if (!$success) {
            return json_encode(array(
                'status' => 'error',
                'message' => $errorDetail
            ));
        }

        return json_encode(array(
            'status' => 'success'
        ));
    }
}
