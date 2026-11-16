<?php

namespace App\Controller;

use App\Model\PanelSettings;
use App\Security\PanelPermissionProvider;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\FieldType\DBHTMLText;

class TouchPanelSplashController extends Controller
{
    private static string $url_segment = 'panel/splash';

    private static array $allowed_actions = [
        'index',
    ];

    public function Link($action = null): string
    {
        return self::join_links('panel/splash', $action);
    }

    protected function init(): void
    {
        parent::init();
        $res = $this->getResponse();
        $res->addHeader("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0");
        $res->addHeader("Pragma", "no-cache");
        $res->addHeader("Expires", "0");
        if (!PanelSecurityGuard::isIPAllowed($this->getRequest())) {
            $this->httpError(403, 'Access denied from this network.');
        }
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        $branding = PanelSettings::current();
        $isAuthenticated = PanelSecurityGuard::isAuthenticated($request);
        $nextRoute = '/panel/pin';

        if ($isAuthenticated) {
            $nextRoute = $this->getFirstPermittedUrl($request) ?? '/panel/pin';
        }

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
            'NextRoute' => $nextRoute,
            'IsAuthenticated' => $isAuthenticated,
        ])->renderWith([
            'App\\Controller\\TouchPanelSplash',
            'Page',
        ]);
    }

    protected function getFirstPermittedUrl(HTTPRequest $request): ?string
    {
        return PanelSecurityGuard::getFirstPermittedUrl($request);
    }
}
