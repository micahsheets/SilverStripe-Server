<?php

namespace App\Traits;

use App\Security\VPXSecurityGuard;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;

trait TouchPanelControllerTrait
{
    protected function enforcePanelGuard(HTTPRequest $request, string $permissionCode, bool $isApi = false): void
    {
        if (!VPXSecurityGuard::isIPAllowed($request)) {
            if ($isApi) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'IP not allowed.'], 403));
            }
            $this->httpError(403, 'Access denied from this network.');
        }

        if (!VPXSecurityGuard::isAuthenticated($request)) {
            if ($isApi) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Auth required.'], 401));
            }
            throw new HTTPResponse_Exception($this->redirect('/panel/splash'));
        }

        if (!VPXSecurityGuard::canAccess($request, $permissionCode)) {
            if ($isApi) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403));
            }
            throw new HTTPResponse_Exception($this->redirect('/panel/pin?err=denied'));
        }

        if ($isApi && $request->isPOST() && !VPXSecurityGuard::isValidCSRF($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403));
        }
    }

    protected function parseRequestData(HTTPRequest $request): array
    {
        $body = $request->getBody();
        if ($body !== '' && str_contains((string) $request->getHeader('Content-Type'), 'application/json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $request->postVars();
    }

    protected function jsonResponse(array $payload, int $statusCode = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_PRETTY_PRINT))
            ->setStatusCode($statusCode)
            ->addHeader('Content-Type', 'application/json');
    }
}
