<?php

namespace App\Controller;

use App\Model\PanelSettings;
use App\Security\VPXPermissionProvider;
use App\Security\VPXSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\SecurityToken;

class PanelAuthController extends Controller
{
    private static array $url_handlers = [
        '' => 'index',
        'pin' => 'pin',
        'unlock' => 'unlock',
        'lock' => 'lock',
        'discover' => 'discover',
    ];

    private static array $allowed_actions = [
        'index',
        'pin',
        'unlock',
        'lock',
        'discover',
    ];

    protected function init(): void
    {
        parent::init();
        if (!VPXSecurityGuard::isIPAllowed($this->getRequest())) {
            $this->httpError(403, 'Access denied from this network.');
        }
    }

    public function index(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $path = trim(strtok($request->getURL(), '?'), '/');

        return match ($path) {
            'panel/unlock' => $this->unlock($request),
            'panel/lock' => $this->lock($request),
            'panel/discover' => $this->discover($request),
            default => $this->pin($request),
        };
    }

    public function pin(HTTPRequest $request): DBHTMLText
    {
        $branding = PanelSettings::current();
        $session = $request->getSession();
        $csrf = SecurityToken::inst()->getValue();
        $session->set('VPXLockInit', 1);
        $session->save($request);

        $errorMsg = match ((string) $request->getVar('err')) {
            '1', 'pinfail' => 'Invalid Operator PIN',
            'emptypin' => 'Please enter a PIN',
            'denied' => 'Your PIN does not have permission for any active module',
            default => '',
        };

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
            'ErrorMessage' => $errorMsg,
            'CSRFToken' => $csrf,
        ])->renderWith([
            'App\\Controller\\TouchPanelLock',
            'Page',
        ]);
    }

    public function unlock(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->redirect('/panel/pin?err=notpost');
        }

        $pin = (string) $request->postVar('pin');
        if ($pin === '') {
            return $this->redirect('/panel/pin?err=emptypin');
        }

        if (!VPXSecurityGuard::verifyPIN($request, $pin)) {
            return $this->redirect('/panel/pin?err=pinfail');
        }

        $request->getSession()->save($request);
        $target = $this->getFirstPermittedUrl($request);
        if ($target !== null) {
            return $this->redirect($target);
        }

        VPXSecurityGuard::lock($request);
        $request->getSession()->save($request);
        return $this->redirect('/panel/pin?err=denied');
    }

    public function lock(HTTPRequest $request): HTTPResponse
    {
        VPXSecurityGuard::lock($request);
        $request->getSession()->save($request);
        return $this->redirect('/panel/splash');
    }

    public function discover(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $session = $request->getSession();

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            $session->save($request);
            return $this->redirect('/panel/splash');
        }

        $session->save($request);
        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
        ])->renderWith([
            'App\\Controller\\TouchPanelDiscover',
            'Page',
        ]);
    }

    protected function getFirstPermittedUrl(HTTPRequest $request): ?string
    {
        $settings = PanelSettings::current();
        foreach ($settings->ActiveModules()->sort('SortOrder') as $mod) {
            $cleanRoute = '/' . ltrim((string) $mod->Route, '/');
            if ($cleanRoute === '/panel' || $cleanRoute === '/panel/splash') {
                continue;
            }
            if (VPXSecurityGuard::canAccess($request, $mod->PermissionCode)) {
                return $cleanRoute;
            }
        }

        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_MATRIX)) {
            return '/panel/video';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX)) {
            return '/panel/audio';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC)) {
            return '/panel/lighting';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_CAMERA)) {
            return '/panel/camera';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_RODECASTER)) {
            return '/panel/rodecaster';
        }
        if (VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return '/panel/discover';
        }

        return null;
    }
}
