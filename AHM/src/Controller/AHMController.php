<?php

namespace App\AHM\Controller;

use App\AHM\Model\AHMDevice;
use App\AHM\Service\AHMService;
use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Security\PanelSecurityGuard;
use App\Traits\TouchPanelControllerTrait;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use Throwable;

class AHMController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    use TouchPanelControllerTrait;

    public const PERM_ACCESS = 'PANEL_ACCESS_AHM';

    private static string $url_segment = 'panel/ahm';

    private static array $url_handlers = [
        ''             => 'index',
        'status'       => 'status',
        'api'          => 'api',
        'sync-catalog' => 'syncCatalog',
    ];

    private static array $allowed_actions = [
        'index',
        'status',
        'api',
        'syncCatalog',
    ];

    public static function getModuleTitle(): string { return 'AHM Mixer'; }
    public static function getModuleRoute(): string { return '/panel/ahm'; }
    public static function getModulePermission(): string { return self::PERM_ACCESS; }
    public static function getModuleSortOrder(): int { return 25; }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name'     => 'Access Allen & Heath AHM Control',
                'category' => 'AV & Control System',
                'help'     => 'Allows adjusting Allen & Heath AHM zones, input faders, mutes, sends, and presets.',
            ],
        ];
    }

    protected function init(): void
    {
        parent::init();
        if ($this->getRequest()->isPOST() && $this->getRequest()->param('Action') !== 'sync-catalog') {
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
            'PanelSettings' => PanelSettings::current(),
            'Devices'       => AHMDevice::get()->filter('Active', 1),
        ])->renderWith([
            'App\\AHM\\Controller\\TouchPanelAHM',
            'Page',
        ]);
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        if (!PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Permission denied'], 403);
        }

        $fetchNames = filter_var($request->getVar('names') ?? false, FILTER_VALIDATE_BOOLEAN);
        $service = AHMService::singleton();
        $list = [];

        foreach (AHMDevice::get()->filter('Active', 1) as $dev) {
            $state = $service->getDeviceState($dev, $fetchNames);
            $list[] = [
                'id'         => (int) $dev->ID,
                'title'      => (string) $dev->Title,
                'ip'         => (string) $dev->IPAddress,
                'port'       => (int) ($dev->Port ?: 51325),
                'model'      => $state['model'],
                'locationId' => (int) $dev->LocationID,
                'online'     => $state['online'],
                'syncedAt'   => $state['syncedAt'],
                'zones'      => $state['zones'],
                'inputs'     => $state['inputs'],
                'groups'     => $state['groups'],
                'sends'      => $state['sends'],
                'presets'    => $state['presets'],
            ];
        }

        return $this->jsonResponse(['success' => true, 'devices' => $list]);
    }

    public function syncCatalog(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        if (!Permission::check('CMS_ACCESS') && !PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            return $this->jsonResponse(['success' => false, 'error' => 'CMS permission required'], 403);
        }

        $data = $this->parseRequestData($request);
        $deviceId = (int) ($data['device_id'] ?? 0);
        $includeAllXpoints = filter_var($data['include_all_xpoints'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $device = AHMDevice::get()->byID($deviceId);
        if (!$device) {
            return $this->jsonResponse(['success' => false, 'error' => 'Save the AHM processor first before syncing.'], 404);
        }

        try {
            $catalog = AHMService::singleton()->fetchFullCatalog($device, $includeAllXpoints);
            $device->DiscoveredCatalog = json_encode($catalog);
            $device->write();
            return $this->jsonResponse(['success' => true, 'catalog' => $catalog]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
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
        $targetType = strtolower(trim((string) ($data['target_type'] ?? 'zone')));
        $number = (int) ($data['number'] ?? 0);
        $value = $data['value'] ?? null;

        $device = AHMDevice::get()->byID($deviceId);
        if (!$device) {
            return $this->jsonResponse(['success' => false, 'error' => 'AHM processor not found'], 404);
        }

        $service = AHMService::singleton();

        try {
            switch ($action) {
                case 'level':
                    $percent = (float) $value;
                    $db = $service->setLevel($device, $targetType, $number, $percent);
                    $this->extend('onPanelAudit', 'Audio_Control', "AHM {$targetType} {$number} level on {$device->Title}", "Level: {$percent}% ({$db} dB)", true, '');
                    return $this->jsonResponse(['success' => true, 'db' => $db]);

                case 'mute':
                    $muted = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $service->setMute($device, $targetType, $number, $muted);
                    $this->extend('onPanelAudit', 'Audio_Control', "AHM {$targetType} {$number} mute on {$device->Title}", 'Muted: ' . ($muted ? 'Yes' : 'No'), true, '');
                    return $this->jsonResponse(['success' => true, 'muted' => $muted]);

                case 'send_level':
                    $in = (int) ($data['input'] ?? 0);
                    $zone = (int) ($data['zone'] ?? 0);
                    $percent = (float) $value;
                    $db = $service->setCrosspointSend($device, $in, $zone, $percent);
                    $this->extend('onPanelAudit', 'Audio_Control', "AHM Send {$in}->{$zone} on {$device->Title}", "Level: {$percent}% ({$db} dB)", true, '');
                    return $this->jsonResponse(['success' => true, 'db' => $db]);

                case 'send_mute':
                    $in = (int) ($data['input'] ?? 0);
                    $zone = (int) ($data['zone'] ?? 0);
                    $muted = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $service->setCrosspointMute($device, $in, $zone, $muted);
                    $this->extend('onPanelAudit', 'Audio_Control', "AHM Send {$in}->{$zone} mute on {$device->Title}", 'Muted: ' . ($muted ? 'Yes' : 'No'), true, '');
                    return $this->jsonResponse(['success' => true, 'muted' => $muted]);

                case 'preset':
                    $presetNum = (int) $value;
                    $service->recallPreset($device, $presetNum);
                    $this->extend('onPanelAudit', 'Audio_Control', "AHM Preset {$presetNum} on {$device->Title}", "Preset: {$presetNum}", true, '');
                    return $this->jsonResponse(['success' => true]);

                default:
                    throw new Exception("Unsupported AHM action: {$action}");
            }
        } catch (Throwable $e) {
            $this->extend('onPanelAudit', 'Audio_Control', "AHM {$action} failed on {$device->Title}", "Target: {$targetType} {$number}", false, $e->getMessage());
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
