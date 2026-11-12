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

class TouchPanelController extends Controller
{
    private static string $url_segment = 'panel';

    private static array $allowed_actions = [
        'index',
        'discover',
        'unlock',
        'lock',
    ];

    private static array $url_handlers = [
        '' => 'index',
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

        public function index(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $branding = PanelSettings::current();

        if (!VPXSecurityGuard::isAuthenticated($request)) {
            $session = $request->getSession();
            $csrf = SecurityToken::inst()->getValue();
            $session->set('VPXLockInit', 1);
            $session->save($request);

            $errorMsg = match ((string) $request->getVar('err')) {
                '1' => 'Invalid Operator PIN',
                'denied' => 'Your PIN does not have permission for that screen',
                default => '',
            };

            return $this->customise([
                'SiteConfig' => $branding,
                'ErrorMessage' => $errorMsg,
                'CSRFToken' => $csrf,
            ])->renderWith(['App/Controller/TouchPanelLock']);
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_MATRIX)) {
            $fallbackUrl = $this->getFirstPermittedUrl($request);
            if ($fallbackUrl !== null) {
                return $this->redirect($fallbackUrl);
            }

            VPXSecurityGuard::lock($request);
            $request->getSession()->save($request);
            return $this->redirect('/panel?err=denied');
        }

        return $this->customise([
            'SiteConfig' => $branding,
        ])->renderWith(['App/Controller/TouchPanelController']);
    }


    public function discover(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        if (!VPXSecurityGuard::isAuthenticated($request)) {
            return $this->redirect('/panel');
        }

        if (!VPXSecurityGuard::canAccess($request, VPXPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            $this->httpError(403, 'You do not have permission to access Hardware Discovery.');
        }

        return $this->customise([
            'SiteConfig' => PanelSettings::current(),
        ])->renderWith(['App/Controller/TouchPanelDiscover']);
    }

    public function unlock(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->redirect('/panel?err=notpost');
        }

        $pin = (string) $request->postVar('pin');
        if ($pin === '') {
            return $this->redirect('/panel?err=emptypin');
        }

        if (!VPXSecurityGuard::verifyPIN($request, $pin)) {
            return $this->redirect('/panel?err=pinfail');
        }

        $request->getSession()->save($request);
        $target = $this->getFirstPermittedUrl($request);
        if ($target !== null) {
            return $this->redirect($target);
        }

        VPXSecurityGuard::lock($request);
        return $this->redirect('/panel?err=denied');
    }



    public function lock(HTTPRequest $request): HTTPResponse
    {
        VPXSecurityGuard::lock($request);
        return $this->redirect('/panel');
    }

    /**
     * Template helpers for showing/hiding navigation tabs in .ss templates
     */
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

    public function CanAccessDiscovery(): bool
    {
        return VPXSecurityGuard::canAccess($this->getRequest(), VPXPermissionProvider::PERM_ACCESS_DISCOVERY);
    }

    public function CurrentActorName(): string
    {
        return VPXSecurityGuard::getActorLabel($this->getRequest());
    }

    /**
     * Resolves the landing route for the authenticated PIN based on their assigned permissions.
     * Update '/panel/audio' and '/panel/lighting' once you register those controllers/routes.
     */
    protected function getFirstPermittedUrl(HTTPRequest $request): ?string
    {
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

        return null;
    }
}
