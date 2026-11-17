<?php

namespace App\Admin;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\SecurityToken;
use ZipArchive;

class SystemUpdateAdmin extends LeftAndMain
{
    private static string $url_segment = 'system-update';

    private static string $menu_title = 'System Update';

    private static string $menu_icon_class = 'font-icon-upload';

    private static int $menu_priority = -10;

    private static array $url_handlers = [
        'POST upload' => 'upload',
        'upload'      => 'upload',
    ];

    private static array $allowed_actions = [
        'upload',
    ];

    public function getSecurityID(): string
    {
        return SecurityToken::inst()->getValue();
    }

    public function upload(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF security token — refresh the page and retry'], 403);
        }

        $uploaded = $request->postVar('patch');
        if (empty($uploaded['tmp_name']) || !is_uploaded_file($uploaded['tmp_name'])) {
            $errCode = $uploaded['error'] ?? 'none';
            return $this->jsonResponse(['success' => false, 'error' => "No valid .zip patch uploaded (PHP upload error code: {$errCode})"], 400);
        }

        $zip = new ZipArchive();
        if ($zip->open($uploaded['tmp_name']) !== true) {
            return $this->jsonResponse(['success' => false, 'error' => 'Corrupted ZIP archive'], 400);
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

        // 2. Block path traversal entries
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                return $this->jsonResponse(['success' => false, 'error' => "Unsafe path in archive: {$entry}"], 400);
            }
        }

        // 3. Extract entry-by-entry so we get exact diagnostics if any file is unwritable
        $written = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === 'manifest.json') {
                continue;
            }
            $dest = BASE_PATH . '/' . $entry;
            if (str_ends_with($entry, '/')) {
                if (!is_dir($dest) && !@mkdir($dest, 0775, true)) {
                    $zip->close();
                    return $this->jsonResponse(['success' => false, 'error' => "Cannot create directory: {$entry}"], 500);
                }
                continue;
            }
            $parentDir = dirname($dest);
            if (!is_dir($parentDir) && !@mkdir($parentDir, 0775, true)) {
                $zip->close();
                return $this->jsonResponse(['success' => false, 'error' => "Cannot create parent directory for: {$entry}"], 500);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false || @file_put_contents($dest, $contents) === false) {
                $zip->close();
                return $this->jsonResponse(['success' => false, 'error' => "Permission denied writing: {$entry} (run chown/chmod for www-data on Pi)"], 500);
            }
            $written++;
        }
        $zip->close();

        // 4. Flush cache and rebuild database schema
        $cmd = sprintf(
            'rm -rf /tmp/silverstripe-cache* %s/silverstripe-cache* && %s/vendor/bin/sake db:build --flush 2>&1',
            escapeshellarg(BASE_PATH),
            escapeshellarg(BASE_PATH)
        );
        exec($cmd, $output, $exitCode);

        return $this->jsonResponse([
            'success'      => $exitCode === 0,
            'version'      => $manifest['version'] ?? 'unknown',
            'modules'      => $manifest['modules'] ?? [],
            'filesWritten' => $written,
            'buildOutput'  => implode("\n", $output),
        ], $exitCode === 0 ? 200 : 500);
    }

    private function jsonResponse(array $payload, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_PRETTY_PRINT), $status)
            ->addHeader('Content-Type', 'application/json');
    }
}
