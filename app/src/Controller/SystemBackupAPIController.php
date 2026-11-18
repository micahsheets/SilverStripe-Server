<?php

namespace App\Controller;

use App\Service\SystemBackupService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;
use Throwable;

class SystemBackupAPIController extends Controller
{
    private static string $url_segment = 'api/system-backup';

    private static array $allowed_actions = [
        'export',
        'inspect',
        'restore',
    ];

    private function canAccess(): bool
    {
        return Permission::check('ADMIN')
            || Permission::check('CMS_ACCESS_App\Admin\SystemBackupAdmin');
    }

    public function export(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canAccess()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Admin access required.'], 403);
        }

        if ($request->isPOST() && !SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token. Refresh the page and try again.'], 403);
        }

        $selected = $request->requestVar('modules');
        if (!is_array($selected) || empty($selected)) {
            $all = SystemBackupService::singleton()->getDiscoverableModules();
            $selected = array_keys($all);
        }
        $includeAssets = (bool) ($request->requestVar('include_assets') ?? false);

        try {
            $zipPath = SystemBackupService::singleton()->createBackupZip($selected, $includeAssets);
            $bytes = file_get_contents($zipPath);
            @unlink($zipPath);

            $filename = 'av-control-backup-' . date('Y-m-d_His') . '.zip';
            return HTTPResponse::create($bytes, 200)
                ->addHeader('Content-Type', 'application/zip')
                ->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->addHeader('Content-Length', (string) strlen($bytes));
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function inspect(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canAccess()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized or invalid method.'], 403);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $file = $_FILES['backup_zip'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return $this->jsonResponse(['success' => false, 'error' => 'Please select a valid .zip backup file.'], 400);
            }
            $manifest = SystemBackupService::singleton()->inspectBackupZip($file['tmp_name']);
            return $this->jsonResponse([
                'success'  => true,
                'manifest' => $manifest,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function restore(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canAccess()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized or invalid method.'], 403);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $file = $_FILES['backup_zip'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return $this->jsonResponse(['success' => false, 'error' => 'Please upload a .zip backup file.'], 400);
            }

            $selectedModules = $request->postVar('modules');
            $onlyModules = is_array($selectedModules) ? $selectedModules : null;
            $mode = ($request->postVar('restore_mode') === 'merge') ? 'merge' : 'replace';

            $stats = SystemBackupService::singleton()->restoreBackupZip($file['tmp_name'], $onlyModules, $mode);

            return $this->jsonResponse([
                'success' => true,
                'stats'   => $stats,
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
