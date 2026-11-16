<?php

namespace App\Security;

use App\Model\PanelSettings;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

class PanelSecurityGuard
{
    public const SESSION_KEY = 'PanelUnlocked';
    public const SESSION_AUTH_TYPE = 'AuthType';       // 'cms' | 'member' | 'basic' | 'master'
    public const SESSION_MEMBER_ID = 'AuthMemberID';

    public const AUTH_TYPE_CMS = 'cms';
    public const AUTH_TYPE_MEMBER = 'member';
    public const AUTH_TYPE_BASIC = 'basic';
    public const AUTH_TYPE_MASTER = 'master';

    public static function isIPAllowed(HTTPRequest $request): bool
    {
        $rawList = (string) Environment::getEnv('PANEL_ALLOWED_SUBNETS');
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

    /**
     * Checks whether the current session is unlocked at all (regardless of specific page permission).
     */
    public static function isAuthenticated(HTTPRequest $request): bool
    {
        if (!self::isAuthRequired()) {
            return true;
        }

        // 1. Active CMS login
        if (Security::getCurrentUser()) {
            return true;
        }

        $session = $request->getSession();
        $authType = (string) $session->get(self::SESSION_AUTH_TYPE);

        // 2. Individual Member PIN session
        if ($authType === self::AUTH_TYPE_MEMBER) {
            $memberId = (int) $session->get(self::SESSION_MEMBER_ID);
            return $memberId > 0 && Member::get()->byID($memberId) !== null;
        }

        // 3. Basic Shared Admin PIN session
        if ($authType === self::AUTH_TYPE_BASIC) {
            $settings = PanelSettings::current();
            return !empty($settings->BasicPanelPIN)
                && $session->get(self::SESSION_KEY) === hash('sha256', 'basic:' . $settings->BasicPanelPIN);
        }

        // 4. Master .env Fallback PIN session
        if ($authType === self::AUTH_TYPE_MASTER) {
            $pin = (string) Environment::getEnv('PANEL_PANEL_PIN');
            return $pin !== '' && $session->get(self::SESSION_KEY) === hash('sha256', 'master:' . $pin);
        }

        return false;
    }

    /**
     * Checks if the current authenticated user/session has permission to access a specific interface
     * (e.g., PanelPermissionProvider::PERM_ACCESS_MATRIX, PERM_ACCESS_AUDIO_DTX, PERM_ACCESS_LIGHTING_ENTTEC).
     */
    public static function canAccess(HTTPRequest $request, string $permissionCode): bool
    {
        if (!self::isAuthRequired()) {
            return true;
        }

        if (!self::isAuthenticated($request)) {
            return false;
        }

        // 1. Active CMS user: check Silverstripe group permissions directly
        $cmsMember = Security::getCurrentUser();
        if ($cmsMember) {
            return Permission::checkMember($cmsMember, $permissionCode)
                || Permission::checkMember($cmsMember, 'ADMIN');
        }

        $session = $request->getSession();
        $authType = (string) $session->get(self::SESSION_AUTH_TYPE);

        // 2. Master .env fallback PIN: full administrative override
        if ($authType === self::AUTH_TYPE_MASTER) {
            return true;
        }

        // 3. Individual Member PIN: evaluate Silverstripe group permissions for that Member
        if ($authType === self::AUTH_TYPE_MEMBER) {
            $member = self::getAuthenticatedMember($request);
            if (!$member) {
                return false;
            }
            return Permission::checkMember($member, $permissionCode)
                || Permission::checkMember($member, 'ADMIN');
        }

        // 4. Basic Shared Admin PIN: check allowed interfaces configured in PanelSettings
        if ($authType === self::AUTH_TYPE_BASIC) {
            return PanelSettings::current()->basicPINAllows($permissionCode);
        }

        return false;
    }

    /**
     * Evaluates a submitted keypad PIN against the 3-tier hierarchy:
     *   Tier 1: Individual Member PanelPIN (from /admin/security)
     *   Tier 2: Basic Shared PanelSettings PIN (from /admin/av-system)
     *   Tier 3: Emergency Master .env PANEL_PANEL_PIN
     */
    public static function verifyPIN(HTTPRequest $request, string $submittedPin): bool
    {
        $pin = trim($submittedPin);
        if ($pin === '') {
            return false;
        }

        $session = $request->getSession();

        // Tier 1: Check individual Member PINs
        foreach (Member::get() as $member) {
            $storedHash = (string) $member->getField('PanelPIN');
            if ($storedHash !== '' && password_verify($pin, $storedHash)) {
                $session->set(self::SESSION_AUTH_TYPE, self::AUTH_TYPE_MEMBER);
                $session->set(self::SESSION_MEMBER_ID, (int) $member->ID);
                $session->set(self::SESSION_KEY, hash('sha256', 'member:' . $member->ID));
                return true;
            }
        }



        // Tier 2: Check Basic Shared PIN in PanelSettings
        $settings = PanelSettings::current();
        if ($settings->verifyBasicPIN($pin)) {
            $session->set(self::SESSION_AUTH_TYPE, self::AUTH_TYPE_BASIC);
            $session->clear(self::SESSION_MEMBER_ID);
            $session->set(self::SESSION_KEY, hash('sha256', 'basic:' . $settings->BasicPanelPIN));
            return true;
        }

        // Tier 3: Emergency Master .env Fallback PIN
        $masterPin = (string) Environment::getEnv('PANEL_PANEL_PIN');
        if ($masterPin !== '' && hash_equals($masterPin, $pin)) {
            $session->set(self::SESSION_AUTH_TYPE, self::AUTH_TYPE_MASTER);
            $session->clear(self::SESSION_MEMBER_ID);
            $session->set(self::SESSION_KEY, hash('sha256', 'master:' . $masterPin));

            // Record an audit entry whenever the emergency master .env PIN is used
            static::singleton()->extend('onPanelSecurityAudit', $ip, $reason);

            return true;
        }

        return false;
    }

    /**
     * Clears touch panel PIN authentication from the current session.
     */
    public static function lock(HTTPRequest $request): void
    {
        $session = $request->getSession();
        $session->clear(self::SESSION_KEY);
        $session->clear(self::SESSION_AUTH_TYPE);
        $session->clear(self::SESSION_MEMBER_ID);
        $session->save($request);
    }

    /**
     * Returns the Member associated with the current session (via CMS login or Member PIN), or null.
     */
    public static function getAuthenticatedMember(HTTPRequest $request): ?Member
    {
        $cmsMember = Security::getCurrentUser();
        if ($cmsMember) {
            return $cmsMember;
        }

        $session = $request->getSession();
        if ($session->get(self::SESSION_AUTH_TYPE) === self::AUTH_TYPE_MEMBER) {
            $memberId = (int) $session->get(self::SESSION_MEMBER_ID);
            if ($memberId > 0) {
                return Member::get()->byID($memberId);
            }
        }

        return null;
    }

    /**
     * Returns a human-readable actor label for RouteAuditLog entries (e.g., "Jane Doe", "Basic Panel PIN", "Master .env PIN").
     */
    public static function getActorLabel(HTTPRequest $request): string
    {
        $member = self::getAuthenticatedMember($request);
        if ($member) {
            return trim($member->getTitle()) ?: ('Member #' . $member->ID);
        }

        $authType = (string) $request->getSession()->get(self::SESSION_AUTH_TYPE);
        if ($authType === self::AUTH_TYPE_BASIC) {
            return 'Basic Panel PIN';
        }
        if ($authType === self::AUTH_TYPE_MASTER) {
            return 'Master .env PIN';
        }

        return 'Unauthenticated / Open Panel';
    }

    public static function isValidCSRF(HTTPRequest $request): bool
    {
        $token = (string) ($request->getHeader('X-CSRF-TOKEN') ?: $request->postVar('SecurityID'));
        return $token !== '' && hash_equals(SecurityToken::inst()->getValue(), $token);
    }

    private static function isAuthRequired(): bool
    {
        $rawRequire = Environment::getEnv('PANEL_REQUIRE_CMS_OR_PIN');
        return $rawRequire === false ? true : filter_var($rawRequire, FILTER_VALIDATE_BOOLEAN);
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

    public static function getFirstPermittedUrl(HTTPRequest $request): ?string
    {
        $settings = PanelSettings::current();
        $candidates = $settings->ActiveModules()->sort('SortOrder');
        if ($candidates->count() === 0) {
            $candidates = \App\Model\SystemModule::get()->sort('SortOrder');
        }

        foreach ($candidates as $mod) {
            $cleanRoute = '/' . ltrim((string) $mod->Route, '/');
            if ($cleanRoute === '/panel' || $cleanRoute === '/panel/splash') {
                continue;
            }
            if (self::canAccess($request, (string) $mod->PermissionCode)) {
                return $cleanRoute;
            }
        }

        if (self::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY)) {
            return '/panel/discover';
        }

        return null;
    }
}
