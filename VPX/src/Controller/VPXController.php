<?php

namespace App\VPX\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\PermissionProvider;

class VPXController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    public const PERM_ACCESS = 'PANEL_ACCESS_MATRIX';

    private static string $url_segment = 'panel/video';

    private static array $url_handlers = [
        '' => 'index',
    ];

    private static array $allowed_actions = [
        'index',
    ];

    public static function getModuleTitle(): string
    {
        return 'Video Matrix';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/video';
    }

    public static function getModulePermission(): string
    {
        return self::PERM_ACCESS;
    }

    public static function getModuleSortOrder(): int
    {
        return 10;
    }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name' => 'Access Video Matrix Control Panel',
                'category' => 'AV & Control System',
                'help' => 'Allows routing encoders/decoders, triggering presets, and controlling display power.',
            ],
        ];
    }

    protected function init(): void
    {
        parent::init();
        if (!PanelSecurityGuard::isIPAllowed($this->getRequest())) {
            $this->httpError(403, 'Access denied from this network.');
        }
    }

    public function Link($action = null): string
    {
        return self::join_links('panel/video', $action);
    }

    public function index(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $session = $request->getSession();

        if (!PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            $session->save($request);
            return $this->redirect('/panel/splash');
        }

        $session->save($request);
        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
        ])->renderWith([
            'App\\VPX\\Controller\\TouchPanelVideo',
            'Page',
        ]);
    }
}
