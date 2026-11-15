<?php

namespace App\Controller;

use App\Model\DTXDevice;
use App\Model\RouteAuditLog;
use App\Security\VPXPermissionProvider;
use App\Security\VPXSecurityGuard;
use App\Service\DTXAudioService;
use App\Service\DTXDiscoveryService;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use Throwable;

class DTXController extends Controller
{
    private static string $url_segment = 'panel/audio';

    private static array $url_handlers = [
        '' => 'index',
        'api' => 'api',
        'discover' => 'discover',
        'import' => 'import',
    ];

    private static array $allowed_actions = [
        'index',
        'api',
        'discover',
        'import',
    ];

    protected function init(): void
    {
        parent::init();

        if ($this->getRequest()->isPOST()) {
            if (!VPXSecurityGuard::isValidCSRF($this->getRequest())) {
                $this->httpError(403, 'Invalid CSRF token');
            }
        }
    }

    public function index(HTTPRequest $request)
    {
        $session = $request->getSession();

if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX)) {
    $session->save($request);
    return $this->redirect('/panel/splash');
}

        $session->save($request);

        return $this->customise([
            'Devices' => DTXDevice::get()->filter('Active', 1),
            'CanAccessDiscovery' => VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY),
        ])->renderWith([
            'App\\Controller\\TouchPanelAudio',
            'Page',
        ]);
    }

    // ... keep your existing api(HTTPRequest $request) method here ...

    public function discover(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        try {
            $data = $this->parseRequestData($request);
            $subnet = trim((string) ($data['subnet'] ?? ''));
            $devices = DTXDiscoveryService::singleton()->scan($subnet);

            return $this->jsonResponse([
                'success' => true,
                'devices' => $devices,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function import(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        try {
            $data = $this->parseRequestData($request);
            $ip = trim((string) ($data['ip'] ?? ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Invalid IP address.'], 400);
            }

            $device = DTXDevice::get()->filter('IPAddress', $ip)->first() ?: DTXDevice::create();
            $device->Title = trim((string) ($data['title'] ?? '')) ?: 'Aurora DTX (' . $ip . ')';
            $device->IPAddress = $ip;
            $device->ControlPort = (int) ($data['controlPort'] ?? 5000) ?: 5000;
            $device->DeviceModel = in_array(($data['model'] ?? ''), ['DTX-44', 'DTX-88'], true)
                ? $data['model']
                : 'DTX-44';
            $device->Active = 1;
            $device->write();

            return $this->jsonResponse([
                'success' => true,
                'id' => (int) $device->ID,
                'title' => $device->Title,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
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
