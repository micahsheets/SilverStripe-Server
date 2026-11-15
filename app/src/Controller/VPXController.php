<?php

namespace App\Controller;

use App\Model\PanelSettings;
use App\Security\VPXPermissionProvider;
use App\Security\VPXSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;

class VPXController extends Controller
{
    private static string $url_segment = 'panel/video';

    private static array $url_handlers = [
        '' => 'index',
    ];

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

    public function Link($action = null): string
    {
        return self::join_links('panel/video', $action);
    }

    public function index(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $session = $request->getSession();

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_MATRIX)) {
            $session->save($request);
            return $this->redirect('/panel/splash');
        }

        $session->save($request);
        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
        ])->renderWith([
            'App\\Controller\\TouchPanelVideo',
            'Page',
        ]);
    }
}
