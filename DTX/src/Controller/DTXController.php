<?php

namespace App\DTX\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\DTX\Model\DTXDevice;
use App\DTX\Service\DTXAudioService;
use App\DTX\Service\DTXDiscoveryService;
use App\Security\PanelPermissionProvider;
use App\Security\PanelSecurityGuard;
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
        $action = strtolower(trim((string) ($data['action'] ?? '')));
        $channel = trim((string) ($data['channel'] ?? ''));
        $value = $data['value'] ?? ($data['level'] ?? null);

        $device = DTXDevice::get()->byID($deviceId);
        if (!$device) {
            return $this->jsonResponse(['success' => false, 'error' => 'DTX device not found'], 404);
        }

        try {
            $service = new DTXAudioService($device->IPAddress, (int) ($device->ControlPort ?: 5000));

            switch ($action) {
                case 'state':
                case 'getstate':
                    $state = $service->getDeviceState((string) ($device->DeviceModel ?: 'DTX-44'));
                    return $this->jsonResponse([
                        'success' => true,
                        'online' => $state['online'],
                        'model' => $state['model'],
                        'channels' => $state['channels'],
                    ]);

                case 'volume':
                case 'setvolume':
                case 'gain':
                    $sliderVal = (float) $value;
                    $service->setChannelVolume($channel, $sliderVal);
                    $db = round(DTXAudioService::sliderToDecibels($sliderVal), 1);
                    $this->extend('onPanelAudit', 'Audio_Control', "DTX volume on {$device->Title}", "Channel: {$channel}, Value: {$sliderVal}% ({$db} dB)", true, '');
                    return $this->jsonResponse(['success' => true, 'db' => $db]);

                case 'mute':
                    $muteBool = ($value === null) ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $service->setChannelMute($channel, $muteBool);
                    $this->extend('onPanelAudit', 'Audio_Control', "DTX mute on {$device->Title}", "Channel: {$channel}, Muted: " . ($muteBool ? 'Yes' : 'No'), true, '');
                    return $this->jsonResponse(['success' => true, 'muted' => $muteBool]);

                case 'unmute':
                    $service->setChannelMute($channel, false);
                    $this->extend('onPanelAudit', 'Audio_Control', "DTX unmute on {$device->Title}", "Channel: {$channel}, Muted: No", true, '');
                    return $this->jsonResponse(['success' => true, 'muted' => false]);

                case 'preset':
                    $service->recallPreset((int) $value);
                    $this->extend('onPanelAudit', 'Audio_Control', "DTX preset on {$device->Title}", "Preset: {$value}", true, '');
                    return $this->jsonResponse(['success' => true]);

                default:
                    throw new Exception("Unsupported DTX action: {$action}");
            }
        } catch (Throwable $e) {
            $this->extend('onPanelAudit', 'Audio_Control', "DTX {$action} failed on {$device->Title}", "Channel: {$channel}, Value: {$value}", false, $e->getMessage());

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
            $items = $data['devices'] ?? [];
            $imported = 0;

            foreach ($items as $item) {
                $ip = trim((string) ($item['ip'] ?? ''));
                if ($ip === '') {
                    continue;
                }
                $dev = DTXDevice::get()->filter('IPAddress', $ip)->first() ?: DTXDevice::create();
                $dev->Title = trim((string) ($item['title'] ?? $item['hostname'] ?? "DTX ({$ip})"));
                $dev->IPAddress = $ip;
                $dev->ControlPort = (int) ($item['controlPort'] ?? 5000);
                $m = strtoupper(trim((string) ($item['model'] ?? 'DTX-44')));
                $dev->DeviceModel = in_array($m, ['DTX-44', 'DTX-88', 'DTX-32', 'DTX-64'], true) ? $m : 'DTX-44';
                $dev->Active = 1;
                $dev->write();
                $imported++;
            }

            return $this->jsonResponse([
                'success' => true,
                'imported' => $imported,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
