<?php

namespace App\Security;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;

class VPXSecurityGuard
{
    public const SESSION_KEY = 'VPXPanelUnlocked';

    public static function isIPAllowed(HTTPRequest $request): bool
    {
        $rawList = (string) Environment::getEnv('VPX_ALLOWED_SUBNETS');
        if (trim($rawList) === '') {
            return true;
        }

        $clientIP = (string) $request->getIP();
        if ($clientIP === '' || $clientIP === '::1') {
            return true;
        }

        foreach (explode(',', $rawList) as $cidr) {
            if (self::ipInRange($clientIP, trim($cidr))) {
                return true;
            }
        }

        return false;
    }

    public static function isAuthenticated(HTTPRequest $request): bool
    {
        $rawRequire = Environment::getEnv('VPX_REQUIRE_CMS_OR_PIN');
        $requireAuth = $rawRequire === false ? true : filter_var($rawRequire, FILTER_VALIDATE_BOOLEAN);
        if (!$requireAuth) {
            return true;
        }

        // 1. Allow any logged-in CMS admin or operator
        if (Permission::check('CMS_ACCESS_App\Admin\AVSystemAdmin') || Permission::check('ADMIN')) {
            return true;
        }

        // 2. Allow touch panels unlocked via PIN in the current session
        $pin = (string) Environment::getEnv('VPX_PANEL_PIN');
        if ($pin !== '' && $request->getSession()->get(self::SESSION_KEY) === hash('sha256', $pin)) {
            return true;
        }

        return false;
    }

    public static function verifyPIN(HTTPRequest $request, string $submittedPin): bool
    {
        $expectedPin = (string) Environment::getEnv('VPX_PANEL_PIN');
        if ($expectedPin === '' || !hash_equals($expectedPin, trim($submittedPin))) {
            return false;
        }

        $request->getSession()->set(self::SESSION_KEY, hash('sha256', $expectedPin));
        return true;
    }

    public static function isValidCSRF(HTTPRequest $request): bool
    {
        $token = (string) ($request->getHeader('X-CSRF-TOKEN') ?: $request->postVar('SecurityID'));
        return $token !== '' && hash_equals(SecurityToken::inst()->getValue(), $token);
    }

    private static function ipInRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
