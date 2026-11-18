<?php

namespace App\Controller;

use App\Auctioneer\Model\AuctionPanelStation;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use Throwable;

class SystemPowerController extends Controller
{
    private static string $url_segment = 'api/system-power';

    private static array $allowed_actions = [
        'status',
        'verifypin',
        'execute',
    ];

    /**
     * Checks if a raw PIN belongs to an ADMIN Member or matches the Master .env PANEL_PANEL_PIN.
     */
    private function isValidAdminPin(string $submittedPin): bool
    {
        $pin = trim($submittedPin);
        if ($pin === '') {
            return false;
        }

        $masterPin = (string) Environment::getEnv('PANEL_PANEL_PIN');
        if ($masterPin !== '' && hash_equals($masterPin, $pin)) {
            return true;
        }

        foreach (Member::get() as $member) {
            $storedHash = (string) $member->getField('PanelPIN');
            if ($storedHash !== '' && password_verify($pin, $storedHash)) {
                if (Permission::checkMember($member, 'ADMIN')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks if the caller is authorized via:
     * 1) Active session with ADMIN privileges (Master PIN, ADMIN Member PIN, or CMS Admin), OR
     * 2) Auctioneer station with CanEditSettings = 1, OR
     * 3) Inline Admin PIN passed directly from Splash/Lock screen.
     */
    private function isAuthorizedForPower(HTTPRequest $request, int $stationId = 0, string $inlinePin = ''): bool
    {
        if (Security::getCurrentUser() && Permission::check('ADMIN')) {
            return true;
        }

        $session = $request->getSession();
        $authType = (string) $session->get(PanelSecurityGuard::SESSION_AUTH_TYPE);

        if ($authType === PanelSecurityGuard::AUTH_TYPE_MASTER) {
            $pin = (string) Environment::getEnv('PANEL_PANEL_PIN');
            if ($pin !== '' && $session->get(PanelSecurityGuard::SESSION_KEY) === hash('sha256', 'master:' . $pin)) {
                return true;
            }
        }

        if ($authType === PanelSecurityGuard::AUTH_TYPE_MEMBER) {
            $member = PanelSecurityGuard::getAuthenticatedMember($request);
            if ($member && Permission::checkMember($member, 'ADMIN')) {
                return true;
            }
        }

        if ($stationId > 0) {
            $station = AuctionPanelStation::get()->byID($stationId);
            $auc = $station ? $station->ActiveAuctioneer() : null;
            if ($auc && $auc->exists() && (bool) $auc->CanEditSettings) {
                return true;
            }
        }

        if ($inlinePin !== '' && $this->isValidAdminPin($inlinePin)) {
            return true;
        }

        return false;
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $stationId = (int) $request->getVar('station');
        return $this->jsonResponse([
            'success'    => true,
            'authorized' => $this->isAuthorizedForPower($request, $stationId),
            'csrfToken'  => SecurityToken::inst()->getValue(),
        ]);
    }

    public function verifypin(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }
        if (!PanelSecurityGuard::isValidCSRF($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $data = json_decode((string) $request->getBody(), true) ?: $request->postVars();
        $pin = trim((string) ($data['pin'] ?? ''));

        if (!$this->isValidAdminPin($pin)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid Admin PIN.'], 401);
        }

        return $this->jsonResponse([
            'success'   => true,
            'csrfToken' => SecurityToken::inst()->getValue(),
        ]);
    }

    public function execute(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        if (!PanelSecurityGuard::isValidCSRF($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $raw = (string) $request->getBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = $request->postVars();
        }

        $action = trim((string) ($data['power_action'] ?? ''));
        $stationId = (int) ($data['station_id'] ?? 0);
        $inlinePin = trim((string) ($data['admin_pin'] ?? ''));

        if (!$this->isAuthorizedForPower($request, $stationId, $inlinePin)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Admin or Edit PIN privileges required.'], 403);
        }

        if (!in_array($action, ['reload', 'restart_app', 'reboot_pi', 'shutdown_pi'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid power action.'], 400);
        }

        try {
            if ($action === 'reload') {
                return $this->jsonResponse([
                    'success' => true,
                    'action'  => 'reload',
                    'message' => 'Reloading touch panel interface...',
                ]);
            }

            if ($action === 'restart_app') {
                @shell_exec('rm -rf /tmp/silverstripe-cache* /var/www/html/silverstripe-cache*');
                @shell_exec('(sleep 1 && apache2ctl graceful) >/dev/null 2>&1 &');
                return $this->jsonResponse([
                    'success' => true,
                    'action'  => 'restart_app',
                    'message' => 'Restarting touch panel service...',
                ]);
            }

            $varDir = BASE_PATH . '/var';
            if (!is_dir($varDir)) {
                @mkdir($varDir, 0775, true);
            }
            $flagFile = $varDir . '/power-command.json';
            $payload = json_encode([
                'action'    => $action,
                'timestamp' => time(),
                'isoTime'   => date('c'),
                'ip'        => (string) $request->getIP(),
            ], JSON_PRETTY_PRINT);

            if (file_put_contents($flagFile, $payload) === false) {
                throw new \RuntimeException('Unable to write host power trigger file (var/power-command.json).');
            }

            return $this->jsonResponse([
                'success' => true,
                'action'  => $action,
                'message' => $action === 'shutdown_pi'
                    ? 'Shutdown signal sent to Raspberry Pi. Wait 15 seconds before disconnecting power.'
                    : 'Reboot signal sent to Raspberry Pi. Panel will reconnect in ~30 seconds.',
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function jsonResponse(array $payload, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload), $status)
            ->addHeader('Content-Type', 'application/json');
    }
}
