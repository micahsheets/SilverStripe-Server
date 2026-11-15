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

class VPXController extends Controller
{
    private static string $url_segment = 'panel';

    private static array $allowed_actions = [
        'index',
        'video',
        'rodecaster',
        'pin',
        'login',
        'discover',
        'unlock',
        'lock',
    ];

    private static array $url_handlers = [
        '' => 'index',
        'video' => 'video',
        'rodecaster' => 'rodecaster',
        'pin' => 'pin',
        'login' => 'login',
        'discover' => 'discover',
        'unlock' => 'unlock',
        'lock' => 'lock',
    ];

    protected function init(): void
    {
        parent::init();
        if (!VPXSecurityGuard::isIPAllowed($this->getRequest())) {
            $this->httpError(403, 'Access denied from this network.');
        }
    }

    /**
     * Entry route (/panel): dynamically forwards to the 1st active & permitted module.
     */
    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!VPXSecurityGuard::isAuthenticated($request)) {
            return $this->redirect('/panel/splash');
        }

        $target = $this->getFirstPermittedUrl($request);
        if ($target !== null) {
            return $this->redirect($target);
        }

        VPXSecurityGuard::lock($request);
        $request->getSession()->save($request);
        return $this->redirect('/panel/pin?err=denied');
    }

    /**
     * Video Matrix Screen (/panel/video)
     */
    public function video(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        if (!VPXSecurityGuard::isAuthenticated($request)) {
            return $this->redirect('/panel/splash');
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_MATRIX)) {
            $fallbackUrl = $this->getFirstPermittedUrl($request);
            if ($fallbackUrl !== null && $fallbackUrl !== '/panel/video') {
                return $this->redirect($fallbackUrl);
            }

            VPXSecurityGuard::lock($request);
            $request->getSession()->save($request);
            return $this->redirect('/panel/pin?err=denied');
        }

        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
        ])->renderWith(['App/Controller/TouchPanelVideo']);
    }

    /**
     * RØDECaster Video Control Screen (/panel/rodecaster)
     */
    public function rodecaster(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        if (!VPXSecurityGuard::isAuthenticated($request)) {
            return $this->redirect('/panel/splash');
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_RODECASTER)) {
            $fallbackUrl = $this->getFirstPermittedUrl($request);
            if ($fallbackUrl !== null && $fallbackUrl !== '/panel/rodecaster') {
                return $this->redirect($fallbackUrl);
            }

            VPXSecurityGuard::lock($request);
            $request->getSession()->save($request);
            return $this->redirect('/panel/pin?err=denied');
        }

        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
        ])->renderWith(['App/Controller/TouchPanelRodeCaster']);
    }

    /**
     * Dedicated PIN Pad Screen (/panel/pin)
     */
    public function pin(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        if (VPXSecurityGuard::isAuthenticated($request)) {
            $target = $this->getFirstPermittedUrl($request);
            if ($target !== null) {
                return $this->redirect($target);
            }
        }

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
        ])->renderWith(['App/Controller/TouchPanelLock']);
    }

    public function login(HTTPRequest $request): HTTPResponse
    {
        return $this->redirect('/panel/splash');
    }

    public function discover(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        if (!VPXSecurityGuard::isAuthenticated($request)) {
            return $this->redirect('/panel/splash');
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            $this->httpError(403, 'You do not have permission to access Hardware Discovery.');
        }

        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings' => $branding,
            'SiteConfig' => $branding,
        ])->renderWith(['App/Controller/TouchPanelDiscover']);
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

    public function CanAccessMatrix(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_MATRIX);
    }

    public function CanAccessAudio(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX);
    }

    public function CanAccessLighting(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC);
    }

    public function CanAccessRodeCaster(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_RODECASTER);
    }

    public function CanAccessDiscovery(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_DISCOVERY);
    }

    public function CurrentActorName(): string
    {
        return VPXSecurityGuard::getActorLabel($this->getRequest());
    }

    /**
     * Resolves the 1st enabled & permitted module from PanelSettings -> ActiveModules.
     */
    protected function getFirstPermittedUrl(HTTPRequest $request): ?string
    {
        $settings = PanelSettings::current();
        $activeModules = $settings->ActiveModules()->sort('SortOrder');

        foreach ($activeModules as $mod) {
            if (isset($mod->Active) && !$mod->Active) {
                continue;
            }
            if (VPXSecurityGuard::canAccess($request, $mod->PermissionCode)) {
                $route = trim((string) $mod->Route);
                if ($route === '/panel' || $route === '') {
                    return '/panel/video';
                }
                return $route;
            }
        }

        return null;
    }
}
