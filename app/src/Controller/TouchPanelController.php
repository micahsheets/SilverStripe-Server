<?php

namespace App\Controller;

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
        'unlock',
        'lock',
    ];

    private static array $url_handlers = [
        '' => 'index',
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

    public function index(HTTPRequest $request): DBHTMLText
    {
        if (!VPXSecurityGuard::isAuthenticated($request)) {
            return $this->customise([
                'ErrorMessage' => $request->getVar('err') ? 'Invalid Operator PIN' : '',
                'CSRFToken' => SecurityToken::inst()->getValue(),
            ])->renderWith(['App/Controller/TouchPanelLock']);
        }

        return $this->renderWith(['App/Controller/TouchPanelController']);
    }

    public function unlock(HTTPRequest $request): HTTPResponse
    {
        if ($request->isPOST() && VPXSecurityGuard::isValidCSRF($request)) {
            $pin = (string) $request->postVar('pin');
            if (VPXSecurityGuard::verifyPIN($request, $pin)) {
                return $this->redirect('/panel');
            }
        }

        return $this->redirect('/panel?err=1');
    }

    public function lock(HTTPRequest $request): HTTPResponse
    {
        $request->getSession()->clear(VPXSecurityGuard::SESSION_KEY);
        return $this->redirect('/panel');
    }
}
