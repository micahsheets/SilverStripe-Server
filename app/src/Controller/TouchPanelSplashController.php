<?php

namespace App\Controller;

use App\Model\PanelSettings;
use App\Security\VPXPermissionProvider;
use App\Security\VPXSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\FieldType\DBHTMLText;

class TouchPanelSplashController extends Controller
{
    private static array $allowed_actions = [
        'index',
    ];

    protected function init(): void
    {
        parent::init();
        if (!VPXSecurityGuard::isIPAllowed($this->getRequest())) {
            $this->httpError(403, 'Access denied from this network.');
        }
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        $branding = PanelSettings::current();
        $isAuthenticated = VPXSecurityGuard::isAuthenticated($request);
        $nextRoute = '/panel/pin';

        if ($isAuthenticated) {
            $nextRoute = $this->getFirstPermittedUrl($request) ?? '/panel/pin';
        }

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
            'NextRoute' => $nextRoute,
            'IsAuthenticated' => $isAuthenticated,
        ])->renderWith(['App/Controller/TouchPanelSplash']);
    }

    protected function getFirstPermittedUrl(HTTPRequest $request): ?string
    {
        $settings = PanelSettings::current();
        foreach ($settings->ActiveModules()->sort('SortOrder') as $mod) {
            if (VPXSecurityGuard::canAccess($request, $mod->PermissionCode)) {
                return $mod->Route;
            }
        }

        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_MATRIX)) {
            return '/panel';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX)) {
            return '/panel/audio';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC)) {
            return '/panel/lighting';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return '/panel/discover';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_RODECASTER)) {
            return '/panel/rodecaster';
        }

        return null;
    }
}
