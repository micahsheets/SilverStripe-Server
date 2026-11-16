<?php

namespace App\DTX\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\DTX\Model\DTXDevice;
use App\Security\PanelPermissionProvider;
use App\Security\PanelSecurityGuard;
use App\DTX\Service\DTXAudioService;
use App\DTX\Service\DTXDiscoveryService;
use App\Traits\TouchPanelControllerTrait;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\PermissionProvider;
use Throwable;

class DTXController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    use TouchPanelControllerTrait;

    public const PERM_ACCESS = 'PANEL_ACCESS_AUDIO_DTX';

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

    public static function getModuleTitle(): string
    {
        return 'Audio DSP';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/audio';
    }

    public static function getModulePermission(): string
    {
        return self::PERM_ACCESS;
    }

    public static function getModuleSortOrder(): int
    {
        return 20;
    }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name' => 'Access Aurora DTX Audio Control',
                'category' => 'AV & Control System',
                'help' => 'Allows viewing and adjusting Aurora DTX DSP audio levels, mutes, and zones.',
            ],
        ];
    }

    protected function init(): void
    {
        parent::init();

        if ($this->getRequest()->isPOST()) {
            if (!PanelSecurityGuard::isValidCSRF($this->getRequest())) {
                $this->httpError(403, 'Invalid CSRF token');
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
            'Devices' => DTXDevice::get()->filter('Active', 1),
            'CanAccessDiscovery' => PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY),
        ])->renderWith([
            'App\\DTX\\Controller\\TouchPanelAudio',
            'Page',
        ]);
    }

    public function api(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }

        if (!PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Permission denied'], 403);
        }

        $data = $this->parseRequestData($request);
        $deviceId = (int) ($data['device_id'] ?? 0);
        $action = trim((string) ($data['action'] ?? ''));
        $channel = trim((string) ($data['channel'] ?? ''));
        $value = $data['value'] ?? null;

        $device = DTXDevice::get()->byID($deviceId);
        if (!$device) {
            return $this->jsonResponse(['success' => false, 'error' => 'DTX device not found'], 404);
        }

        try {
            $service = new DTXAudioService($device->IPAddress, (int) ($device->ControlPort ?: 5000));

            switch ($action) {
                case 'volume':
                case 'gain':
                    $service->setChannelVolume($channel, (float) $value);
                    break;
                case 'mute':
                    $service->setChannelMute($channel, filter_var($value, FILTER_VALIDATE_BOOLEAN));
                    break;
                case 'preset':
                    $service->recallPreset((int) $value);
                    break;
                default:
                    throw new Exception("Unsupported DTX action: {$action}");
            }

            $auditArgs = ['Audio_Control',
                "DTX {$action} on {$device->Title}",
                "Channel: {$channel}, Value: {$value}",
                true,
                ''];
        $this->extend('onPanelAudit', ...$auditArgs);

            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            $auditArgs = ['Audio_Control',
                "DTX {$action} failed on {$device->Title}",
                "Channel: {$channel}, Value: {$value}",
                false,
                $e->getMessage()];
        $this->extend('onPanelAudit', ...$auditArgs);

            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
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

        if (!PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY)) {
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
}
