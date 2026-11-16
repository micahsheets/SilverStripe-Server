<?php

namespace App\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Security;
use ZipArchive;

class SystemUpdateController extends Controller
{
    private static $allowed_actions = [
        'index',
        'upload',
    ];

    private static $url_handlers = [
        'POST upload' => 'upload',
        '' => 'index',
    ];

    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->httpError(403, 'Unauthorized');
        }

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>AV TouchPanel System Update</title>
  <style>
    body { font-family: -apple-system, sans-serif; background: #111827; color: #f9fafb; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
    .card { background: #1f2937; padding: 2rem; border-radius: 12px; width: 420px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
    h2 { margin-top: 0; color: #a78bfa; }
    input[type="file"], input[type="password"] { width: 100%; margin: 0.75rem 0 1.25rem; padding: 0.6rem; background: #374151; border: 1px solid #4b5563; border-radius: 6px; color: #fff; box-sizing: border-box; }
    button { width: 100%; padding: 0.8rem; background: #7c3aed; border: none; border-radius: 6px; color: white; font-weight: 600; font-size: 1rem; cursor: pointer; }
    button:hover { background: #6d28d9; }
    pre { background: #030712; padding: 0.75rem; border-radius: 6px; font-size: 0.8rem; overflow-x: auto; white-space: pre-wrap; display: none; }
  </style>
</head>
<body>
  <div class="card">
    <h2>AV Panel Patch Upload</h2>
    <form id="patchForm" enctype="multipart/form-data">
      <label>Update PIN / Token</label>
      <input type="password" name="token" required placeholder="Enter update token">
      <label>Patch Archive (.zip)</label>
      <input type="file" name="patch" accept=".zip" required>
      <button type="submit">Install Update & Rebuild</button>
    </form>
    <pre id="log"></pre>
  </div>
  <script>
    document.getElementById('patchForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const log = document.getElementById('log');
      log.style.display = 'block';
      log.textContent = 'Uploading and applying patch...';
      const res = await fetch('/panel-update/upload', { method: 'POST', body: new FormData(e.target) });
      const data = await res.json();
      log.textContent = JSON.stringify(data, null, 2);
      if (data.success) {
        log.textContent += '\n\nReloading touch panel in 3 seconds...';
        setTimeout(() => window.location.href = '/panel', 3000);
      }
    });
  </script>
</body>
</html>
HTML;

        return HTTPResponse::create($html);
    }

    public function upload(HTTPRequest $request): HTTPResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid update token or session'], 403);
        }

        $uploaded = $request->postVar('patch');
        if (empty($uploaded['tmp_name']) || !is_uploaded_file($uploaded['tmp_name'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'No valid .zip patch uploaded'], 400);
        }

        $zip = new ZipArchive();
        if ($zip->open($uploaded['tmp_name']) !== true) {
            return $this->jsonResponse(['success' => false, 'error' => 'Corrupted ZIP archive'], 400);
        }

        // 1. Validate manifest.json inside patch
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
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                return $this->jsonResponse(['success' => false, 'error' => "Unsafe path in archive: {$entry}"], 400);
            }
        }

        // 3. Extract files directly into SilverStripe BASE_PATH
        $extractOk = $zip->extractTo(BASE_PATH);
        $zip->close();

        if (!$extractOk) {
            return $this->jsonResponse(['success' => false, 'error' => 'Failed to extract files (check folder permissions)'], 500);
        }

        @unlink(BASE_PATH . '/manifest.json');

        // 4. Flush SilverStripe cache and rebuild DB schema
        $cmd = sprintf(
            'rm -rf /tmp/silverstripe-cache* %s/silverstripe-cache* && %s/vendor/bin/sake db:build --flush 2>&1',
            escapeshellarg(BASE_PATH),
            escapeshellarg(BASE_PATH)
        );
        exec($cmd, $output, $exitCode);

        return $this->jsonResponse([
            'success' => $exitCode === 0,
            'version' => $manifest['version'] ?? 'unknown',
            'buildOutput' => implode("\n", $output),
        ], $exitCode === 0 ? 200 : 500);
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
