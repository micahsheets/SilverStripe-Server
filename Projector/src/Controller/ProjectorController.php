<?php

namespace App\Projector\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Projector\Model\ProjectorDevice;
use App\Traits\TouchPanelControllerTrait;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\SecurityToken;

class ProjectorController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    use TouchPanelControllerTrait;

    public const PERM_ACCESS = 'PANEL_ACCESS_PROJECTOR';

    private static string $url_segment = 'panel/projector';

    private static array $allowed_actions = [
        'index',
        'command',
    ];

    public static function getModuleTitle(): string { return 'Projectors'; }
    public static function getModuleRoute(): string { return '/panel/projector'; }
    public static function getModulePermission(): string { return self::PERM_ACCESS; }
    public static function getModuleSortOrder(): int { return 60; }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name' => 'Access Projector Control',
                'category' => 'AV & Control System',
            ],
        ];
    }

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();
        $isApi = str_starts_with(trim($request->getURL(), '/'), 'api/projector');
        $this->enforcePanelGuard($request, self::PERM_ACCESS, $isApi);
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
            'CSRFToken' => SecurityToken::inst()->getValue(),
            'Projectors' => ProjectorDevice::get()->filter('Active', 1),
        ])->renderWith([
            'App\\Projector\\Controller\\TouchPanelProjector',
            'Page',
        ]);
    }

    public function command(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        $data = $this->parseRequestData($request);
        $device = ProjectorDevice::get()->byID((int) ($data['device_id'] ?? 0));
        $cmd = trim((string) ($data['command'] ?? ''));

        if (!$device) {
            return $this->jsonResponse(['success' => false, 'error' => 'Projector not found.'], 404);
        }
        if ($cmd === '') {
            return $this->jsonResponse(['success' => false, 'error' => 'Command required.'], 400);
        }

        return $this->jsonResponse([
            'success' => true,
            'device' => $device->Title,
            'command' => $cmd,
        ]);
    }
}
