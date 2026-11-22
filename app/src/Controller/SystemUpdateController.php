<?php

namespace App\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DatabaseAdmin;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Throwable;
use ZipArchive;

class SystemUpdateController extends Controller
{
    private static string $url_segment = 'admin/system-update';

    private static array $allowed_actions = [
        'index',
        'upload',
    ];

    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!Security::getCurrentUser()) {
            return Security::permissionFailure($this);
        }
        if (!Permission::check('ADMIN')) {
            return $this->jsonResponse(['success' => false, 'error' => 'Admin permission required'], 403);
        }

        return HTTPResponse::create($this->renderWith(['App\\Controller\\SystemUpdateController', 'Page'])->RAW());
    }

    public function upload(HTTPRequest $request): HTTPResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        if (Security::getCurrentUser() && !Permission::check('ADMIN')) {
            return $this->jsonResponse(['success' => false, 'error' => 'Admin permission required'], 403);
        }

        $uploaded = $request->postVar('patchFile');
        if (!is_array($uploaded) || empty($uploaded['tmp_name']) || !is_uploaded_file($uploaded['tmp_name'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'No valid patch zip uploaded'], 400);
        }

        $zip = new ZipArchive();
        if ($zip->open($uploaded['tmp_name']) !== true) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unable to open zip archive'], 400);
        }

        // 1. Validate manifest.json
        $manifestRaw = $zip->getFromName('manifest.json');
        if (!$manifestRaw) {
            $zip->close();
            return $this->jsonResponse(['success' => false, 'error' => 'Missing manifest.json in patch'], 400);
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['target'] ?? '') !== 'av-touchpanel') {
            $zip->close();
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid patch target in manifest.json'], 400);
        }

        // 2. Prevent path traversal attacks before extracting
        $filesWritten = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                return $this->jsonResponse(['success' => false, 'error' => "Unsafe path in archive: {$entry}"], 400);
            }
            if (!str_ends_with($entry, '/') && $entry !== 'manifest.json') {
                $filesWritten++;
            }
        }

        // 3. Extract files directly into SilverStripe BASE_PATH
        $extractOk = $zip->extractTo(BASE_PATH);
        $zip->close();

        if (!$extractOk) {
            return $this->jsonResponse(['success' => false, 'error' => 'Failed to extract files (check folder permissions)'], 500);
        }

        @unlink(BASE_PATH . '/manifest.json');

        // 4. Reset PHP OPcache and clear SilverStripe cache directories
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        foreach (array_merge(glob('/tmp/silverstripe-cache*') ?: [], glob(BASE_PATH . '/silverstripe-cache*') ?: []) as $cacheDir) {
            $this->deleteDirectory($cacheDir);
        }

        // 5. Rebuild database schema & class manifest in-process (works identically on Mac and Pi)
        $buildLog = '';
        try {
            ob_start();
            $dbAdmin = DatabaseAdmin::create();
            $dbAdmin->doBuild(true, true);
            $buildLog = trim(strip_tags((string) ob_get_clean()));
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return $this->jsonResponse([
                'success' => false,
                'version' => $manifest['version'] ?? 'unknown',
                'modules' => $manifest['modules'] ?? [],
                'filesWritten' => $filesWritten,
                'error' => 'Database rebuild failed: ' . $e->getMessage(),
            ], 500);
        }

        return $this->jsonResponse([
            'success' => true,
            'version' => $manifest['version'] ?? 'unknown',
            'modules' => $manifest['modules'] ?? [],
            'filesWritten' => $filesWritten,
            'buildOutput' => $buildLog !== '' ? $buildLog : 'Database schema and manifest flushed.',
        ], 200);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function isAuthorized(HTTPRequest $request): bool
    {
        if (Security::getCurrentUser()) {
            return true;
        }
        $configuredToken = (string) Environment::getEnv('PANEL_UPDATE_TOKEN');
        $submittedToken = (string) $request->postVar('token');

        return $configuredToken !== '' && hash_equals($configuredToken, $submittedToken);
    }

    private function jsonResponse(array $payload, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_PRETTY_PRINT), $status)
            ->addHeader('Content-Type', 'application/json');
    }
}
