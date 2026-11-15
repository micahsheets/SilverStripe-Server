<?php

namespace App\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Model\RodeCasterDevice;
use App\Service\RodeCasterOSCService;
use App\Traits\TouchPanelControllerTrait;
use RuntimeException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\SecurityToken;

class RodeCasterController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    use TouchPanelControllerTrait;

    public const PERM_ACCESS = 'VPX_ACCESS_RODECASTER';

    private static string $url_segment = 'panel/rodecaster';

    private static array $allowed_actions = [
        'index',
        'status',
        'ping',
        'trigger',
    ];

    private static array $url_handlers = [
        'status'  => 'status',
        'ping'    => 'ping',
        'trigger' => 'trigger',
        ''        => 'index',
    ];

    public static function getModuleTitle(): string
    {
        return 'RØDECaster';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/rodecaster';
    }

    public static function getModulePermission(): string
    {
        return self::PERM_ACCESS;
    }

    public static function getModuleSortOrder(): int
    {
        return 50;
    }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name' => 'Access RØDECaster Video Control',
                'category' => 'AV & Control System',
                'help' => 'Allows switching scenes, inputs, overlays, and media on RØDECaster Video units.',
            ],
        ];
    }

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();
        $isApi = str_starts_with(trim($request->getURL(), '/'), 'api/rodecaster');
        $this->enforcePanelGuard($request, self::PERM_ACCESS, $isApi);
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        $branding = PanelSettings::current();
        $csrf = SecurityToken::inst()->getValue();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig'    => $branding,
            'SecurityID'    => $csrf,
            'CSRFToken'     => $csrf,
        ])->renderWith([
            'App/Controller/TouchPanelRodeCaster',
            'app/Controller/TouchPanelRodeCaster',
        ]);
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $devices = [];
        foreach (RodeCasterDevice::get() as $dev) {
            $devices[] = [
                'id'       => (int) $dev->ID,
                'title'    => $dev->Title,
                'ip'       => $dev->IPAddress,
                'port'     => (int) ($dev->Port ?: 10024),
                'scenes'   => $dev->getButtonList('Scene'),
                'inputs'   => $dev->getButtonList('Input'),
                'overlays' => $dev->getButtonList('Overlay'),
                'media'    => $dev->getButtonList('Media'),
            ];
        }

        return $this->jsonResponse([
            'success'   => true,
            'csrfToken' => SecurityToken::inst()->getValue(),
            'devices'   => $devices,
        ]);
    }

    public function ping(HTTPRequest $request): HTTPResponse
    {
        $service = RodeCasterOSCService::singleton();
        $online = [];
        foreach (RodeCasterDevice::get() as $dev) {
            $online[(int) $dev->ID] = $service->isDeviceOnline($dev);
        }

        return $this->jsonResponse([
            'success' => true,
            'online'  => $online,
        ]);
    }

    public function trigger(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        $payload = json_decode((string) $request->getBody(), true) ?: [];
        $deviceId = (int) ($payload['device_id'] ?? 0);
        $action   = (string) ($payload['action'] ?? '');
        $index    = (int) ($payload['index'] ?? 0);

        $device = RodeCasterDevice::get()->byID($deviceId);
        if (!$device) {
            return $this->jsonResponse(['success' => false, 'error' => 'RØDECaster device not found.'], 404);
        }

        try {
            $result = RodeCasterOSCService::singleton()->triggerAction($device, $action, $index);
            return $this->jsonResponse([
                'success' => true,
                'result'  => $result,
            ]);
        } catch (RuntimeException $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
