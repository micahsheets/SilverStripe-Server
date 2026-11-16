<?php

namespace App\Controller;

use App\Model\PanelSettings;
use App\Security\PanelPermissionProvider;
use App\Security\PanelSecurityGuard;
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
        if (!PanelSecurityGuard::isIPAllowed($this->getRequest())) {
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
        $session->set('PanelLockInit', 1);
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

        if (!PanelSecurityGuard::verifyPIN($request, $pin)) {
            return $this->redirect('/panel/pin?err=pinfail');
        }

        $request->getSession()->save($request);
        $target = $this->getFirstPermittedUrl($request);
        if ($target !== null) {
            return $this->redirect($target);
        }

        PanelSecurityGuard::lock($request);
        $request->getSession()->save($request);
        return $this->redirect('/panel/pin?err=denied');
    }

    public function lock(HTTPRequest $request): HTTPResponse
    {
        PanelSecurityGuard::lock($request);
        $request->getSession()->save($request);
        return $this->redirect('/panel/splash');
    }

    public function discover(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $session = $request->getSession();

        if (!PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY)) {
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
        return PanelSecurityGuard::getFirstPermittedUrl($request);
    }
}
