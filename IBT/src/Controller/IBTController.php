<?php

namespace App\IBT\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\IBT\Model\IBTSettings;
use App\Model\PanelSettings;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\PermissionProvider;

class IBTController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    public const PERM_ACCESS = 'PANEL_ACCESS_IBT';

    private static string $url_segment = 'panel/ibt';

    private static array $url_handlers = [
        '' => 'index',
    ];

    private static array $allowed_actions = [
        'index',
    ];

    public static function getModuleTitle(): string
    {
        return 'IBT Matrix';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/ibt';
    }

    public static function getModulePermission(): string
    {
        return self::PERM_ACCESS;
    }

    public static function getModuleSortOrder(): int
    {
        return 12;
    }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name'     => 'Access Aurora IBT-1-BX Control Panel',
                'category' => 'AV & Control System',
                'help'     => 'Allows routing IBT-1-BX transmitters/receivers, triggering presets, and controlling display power.',
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
        return self::join_links('panel/ibt', $action);
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
        $ibtSettings = IBTSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig'    => $branding,
            'IBTSettings'   => $ibtSettings,
        ])->renderWith([
            'App\\IBT\\Controller\\TouchPanelIBT',
            'Page',
        ]);
    }
}
