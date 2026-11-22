<?php

namespace App\Controller;

use App\Auctioneer\Model\AuctioneerProfile;
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

    public function handleRequest(HTTPRequest $request): HTTPResponse
    {
        if (strtoupper($request->httpMethod()) === 'OPTIONS') {
            return $this->jsonResponse(['success' => true], 200);
        }
        return parent::handleRequest($request);
    }

    /**
     * Checks database ADMIN Member PanelPINs first (hashed or plaintext),
     * then AuctioneerProfile PINs with CanEditSettings = 1,
     * then falls back to the .env PANEL_PANEL_PIN.
     */
    private function isValidAdminPin(string $submittedPin): bool
    {
        $pin = trim($submittedPin);
        if ($pin === '') {
            return false;
        }

        // 1. Primary: Check database Members with ADMIN permission
        foreach (Member::get() as $member) {
            if (!Permission::checkMember($member, 'ADMIN')) {
                continue;
            }
            $stored = (string) $member->getField('PanelPIN');
            if ($stored === '') {
                continue;
            }
            if (password_verify($pin, $stored) || hash_equals($stored, $pin)) {
                return true;
            }
        }

        // 2. Auctioneer profiles granted CanEditSettings = 1
        foreach (AuctioneerProfile::get()->filter(['Active' => 1, 'CanEditSettings' => 1]) as $auc) {
            $aucPin = trim((string) $auc->PinCode);
            if ($aucPin !== '' && hash_equals($aucPin, $pin)) {
                return true;
            }
        }

        // 3. Fallback: Check .env PANEL_PANEL_PIN
        $masterPin = trim((string) Environment::getEnv('PANEL_PANEL_PIN'));
        if ($masterPin !== '' && hash_equals($masterPin, $pin)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the caller is authorized via:
     * 1) Active session with ADMIN privileges (ADMIN Member PIN, Master .env PIN, or CMS Admin), OR
     * 2) Auctioneer station with CanEditSettings = 1, OR
     * 3) Inline Admin/Edit PIN passed directly from Splash/Lock/System PIN modal.
     */
    private function isAuthorizedForPower(HTTPRequest $request, int $stationId = 0, string $inlinePin = ''): bool
    {
        if (Security::getCurrentUser() && Permission::check('ADMIN')) {
            return true;
        }

        $session = $request->getSession();
        $authType = (string) $session->get(PanelSecurityGuard::SESSION_AUTH_TYPE);

        if ($authType === PanelSecurityGuard::AUTH_TYPE_MEMBER) {
            $member = PanelSecurityGuard::getAuthenticatedMember($request);
            if ($member && Permission::checkMember($member, 'ADMIN')) {
                return true;
            }
        }

        if ($authType === PanelSecurityGuard::AUTH_TYPE_MASTER) {
            $pin = trim((string) Environment::getEnv('PANEL_PANEL_PIN'));
            if ($pin !== '' && $session->get(PanelSecurityGuard::SESSION_KEY) === hash('sha256', 'master:' . $pin)) {
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

    private function currentReloadToken(): string
    {
        $parts = [];
        $manifestFile = BASE_PATH . '/app/patch-manifest.json';
        if (is_file($manifestFile)) {
            $parts[] = (string) @filemtime($manifestFile) . ':' . (string) @md5_file($manifestFile);
        }
        $tokenFile = BASE_PATH . '/var/kiosk-reload-token.txt';
        if (is_file($tokenFile)) {
            $parts[] = trim((string) @file_get_contents($tokenFile));
        }
        return implode('|', $parts);
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $stationId = (int) $request->getVar('station');
        return $this->jsonResponse([
            'success'        => true,
            'authorized'     => $this->isAuthorizedForPower($request, $stationId),
            'csrfToken'      => SecurityToken::inst()->getValue(),
            'timeoutMinutes' => max(0, (int) \App\Model\PanelSettings::current()->SessionTimeoutMinutes),
            'reloadToken'    => $this->currentReloadToken(),
        ]);
    }

    public function verifypin(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
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

        $raw = (string) $request->getBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = $request->postVars();
        }

        $action = trim((string) ($data['power_action'] ?? ''));
        $stationId = (int) ($data['station_id'] ?? 0);
        $inlinePin = trim((string) ($data['admin_pin'] ?? ''));

        if (!$this->isAuthorizedForPower($request, $stationId, $inlinePin)) {
            return $this->jsonResponse([
                'success'     => false,
                'pinRequired' => true,
                'error'       => 'Admin or Edit PIN privileges required.',
            ], 403);
        }

        if (!in_array($action, ['reload', 'restart_app', 'reboot_pi', 'shutdown_pi'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid power action.'], 400);
        }

        try {
            if ($action === 'reload') {
                $varDir = BASE_PATH . '/var';
                if (!is_dir($varDir)) {
                    @mkdir($varDir, 0775, true);
                }
                @file_put_contents($varDir . '/kiosk-reload-token.txt', (string) microtime(true));
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
            ->addHeader('Content-Type', 'application/json')
            ->addHeader('Access-Control-Allow-Origin', '*')
            ->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->addHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-TOKEN, X-Requested-With');
    }
}
