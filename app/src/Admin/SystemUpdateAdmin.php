<?php

namespace App\Admin;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Manifest\ClassLoader;
use SilverStripe\Dev\DevBuildController;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\DB;
use SilverStripe\Security\SecurityToken;
use Throwable;
use ZipArchive;

class SystemUpdateAdmin extends LeftAndMain
{
    private static string $url_segment = 'system-update';

    private static string $menu_title = 'System Update';

    private static string $menu_icon_class = 'font-icon-upload';

    private static int $menu_priority = -10;

    private static array $url_handlers = [
        'POST upload'        => 'upload',
        'upload'             => 'upload',
        'GET exportconfig'   => 'exportconfig',
        'exportconfig'       => 'exportconfig',
        'POST importconfig'  => 'importconfig',
        'importconfig'       => 'importconfig',
    ];

    private static array $allowed_actions = [
        'upload',
        'exportconfig',
        'importconfig',
    ];

    public function getSecurityID(): string
    {
        return SecurityToken::inst()->getValue();
    }

    public function getCurrentPatchInfo(): ArrayData
    {
        $manifestFile = BASE_PATH . '/app/patch-manifest.json';
        $data = [
            'HasManifest' => false,
            'Version'     => 'Unrecorded (Pre-Manifest Build)',
            'Created'     => '',
            'InstalledAt' => '',
            'ModulesList' => 'None recorded',
        ];

        if (is_file($manifestFile)) {
            $parsed = json_decode((string) file_get_contents($manifestFile), true);
            if (is_array($parsed) && !empty($parsed['version'])) {
                $modules = is_array($parsed['modules'] ?? null) ? $parsed['modules'] : [];
                $data = [
                    'HasManifest' => true,
                    'Version'     => (string) $parsed['version'],
                    'Created'     => (string) ($parsed['created'] ?? ''),
                    'InstalledAt' => (string) ($parsed['installed_at'] ?? ''),
                    'ModulesList' => !empty($modules) ? implode(', ', $modules) : 'Core only',
                ];
            }
        }

        return ArrayData::create($data);
    }

    /**
     * Exports all database tables (Locations, Devices, Presets, Members/PINs, PanelSettings, Files)
     * plus uploaded public/assets/ into a single downloadable .zip archive for Windows/Mac technicians.
     */
    public function exportconfig(HTTPRequest $request): HTTPResponse
    {
        if (!SecurityToken::inst()->checkRequest($request)) {
            return HTTPResponse::create('Invalid security token — refresh /admin/system-update and retry.', 403);
        }

        $rawLabel = trim((string) $request->getVar('site_name'));
        $safeLabel = preg_replace('/[^A-Za-z0-9_-]+/', '-', $rawLabel) ?: 'site-config';
        $safeLabel = trim($safeLabel, '-') ?: 'site-config';

        $conn = DB::get_conn();
        $tables = $conn->tableList();
        $tableDump = [];

        foreach ($tables as $lowerName => $actualTable) {
            // Skip ephemeral audit/session logs if desired, keep all configuration & user tables
            $rows = [];
            $res = DB::query(sprintf('SELECT * FROM "%s"', str_replace('"', '""', $actualTable)));
            foreach ($res as $row) {
                $rows[] = $row;
            }
            $tableDump[$actualTable] = $rows;
        }

        $bundleMeta = [
            'type'       => 'av-touchpanel-site-config',
            'siteName'   => $rawLabel !== '' ? $rawLabel : 'AV TouchPanel Site',
            'exportedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'tableCount' => count($tableDump),
            'tables'     => $tableDump,
        ];

        $tmpZip = tempnam(sys_get_temp_dir(), 'av_cfg_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return HTTPResponse::create('Unable to create temporary ZIP archive.', 500);
        }

        $zip->addFromString(
            'site-config.json',
            json_encode($bundleMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Include uploaded logos, backgrounds, and media from public/assets
        $assetsRoot = BASE_PATH . '/public/assets';
        if (is_dir($assetsRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($assetsRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $fileInfo) {
                $fullPath = $fileInfo->getPathname();
                $relPath = 'assets/' . ltrim(substr($fullPath, strlen($assetsRoot)), '/\\');
                $relPath = str_replace('\\', '/', $relPath);
                if ($fileInfo->isDir()) {
                    $zip->addEmptyDir($relPath);
                } elseif ($fileInfo->isFile()) {
                    $zip->addFile($fullPath, $relPath);
                }
            }
        }

        $zip->close();
        $binary = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);

        $filename = sprintf('%s-%s.zip', $safeLabel, date('Ymd-His'));

        return HTTPResponse::create($binary, 200)
            ->addHeader('Content-Type', 'application/zip')
            ->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->addHeader('Content-Length', (string) strlen($binary));
    }

    /**
     * Restores a previously exported Site Configuration (.zip) onto the target Raspberry Pi or staging bench.
     */
    public function importconfig(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF security token — refresh the page and retry'], 403);
        }

        $uploaded = $request->postVar('site_bundle');
        if (empty($uploaded['tmp_name']) || !is_uploaded_file($uploaded['tmp_name'])) {
            $errCode = $uploaded['error'] ?? 'none';
            return $this->jsonResponse(['success' => false, 'error' => "No valid site configuration .zip uploaded (PHP upload error: {$errCode})"], 400);
        }

        $zip = new ZipArchive();
        if ($zip->open($uploaded['tmp_name']) !== true) {
            return $this->jsonResponse(['success' => false, 'error' => 'Corrupted ZIP archive'], 400);
        }

        $configRaw = $zip->getFromName('site-config.json');
        if (!$configRaw) {
            $zip->close();
            return $this->jsonResponse(['success' => false, 'error' => 'Missing site-config.json inside archive. Make sure you selected a Site Configuration bundle, not a code patch.'], 400);
        }

        $bundle = json_decode($configRaw, true);
        if (!is_array($bundle) || ($bundle['type'] ?? '') !== 'av-touchpanel-site-config' || !is_array($bundle['tables'] ?? null)) {
            $zip->close();
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid site configuration format in site-config.json'], 400);
        }

        // Validate paths before extracting assets
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                return $this->jsonResponse(['success' => false, 'error' => "Unsafe path in archive: {$entry}"], 400);
            }
        }

        // 1. Extract uploaded assets into public/assets/
        $assetsRestored = 0;
        $assetsRoot = BASE_PATH . '/public/assets';
        if (!is_dir($assetsRoot)) {
            @mkdir($assetsRoot, 0775, true);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (!str_starts_with($entry, 'assets/')) {
                continue;
            }
            $rel = substr($entry, strlen('assets/'));
            if ($rel === '') {
                continue;
            }
            $dest = $assetsRoot . '/' . $rel;
            if (str_ends_with($entry, '/')) {
                if (!is_dir($dest)) {
                    @mkdir($dest, 0775, true);
                }
                continue;
            }
            $parent = dirname($dest);
            if (!is_dir($parent)) {
                @mkdir($parent, 0775, true);
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes !== false && @file_put_contents($dest, $bytes) !== false) {
                $assetsRestored++;
            }
        }
        $zip->close();

        // 2. Ensure schema exists first, then restore all matching tables cleanly
        $tablesRestored = 0;
        $rowsRestored = 0;
        try {
            $conn = DB::get_conn();
            $existingTables = $conn->tableList(); // lowercase => actual
            $pdo = null;
            if (method_exists($conn, 'getConnector') && method_exists($conn->getConnector(), 'getPDO')) {
                $pdo = $conn->getConnector()->getPDO();
            }

            DB::query('SET FOREIGN_KEY_CHECKS=0');

            foreach ($bundle['tables'] as $tableName => $rows) {
                $lower = strtolower((string) $tableName);
                if (!isset($existingTables[$lower]) || !is_array($rows)) {
                    continue;
                }
                $actualTable = $existingTables[$lower];
                $quotedTable = '"' . str_replace('"', '""', $actualTable) . '"';

                // Discover existing columns on target host so cross-version imports never fail on removed/added columns
                $validCols = [];
                $colRes = DB::query(sprintf('SHOW COLUMNS FROM %s', $quotedTable));
                foreach ($colRes as $colInfo) {
                    if (!empty($colInfo['Field'])) {
                        $validCols[$colInfo['Field']] = true;
                    }
                }

                DB::query(sprintf('TRUNCATE TABLE %s', $quotedTable));

                foreach ($rows as $row) {
                    if (!is_array($row) || empty($row)) {
                        continue;
                    }
                    $filtered = array_intersect_key($row, $validCols);
                    if (empty($filtered)) {
                        continue;
                    }

                    $cols = array_keys($filtered);
                    $quotedCols = array_map(fn($c) => '"' . str_replace('"', '""', (string) $c) . '"', $cols);

                    if ($pdo instanceof \PDO) {
                        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $quotedTable, implode(', ', $quotedCols), $placeholders);
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute(array_values($filtered));
                    } else {
                        $vals = array_map(function ($v) use ($conn) {
                            if ($v === null) {
                                return 'NULL';
                            }
                            return "'" . $conn->escapeString((string) $v) . "'";
                        }, array_values($filtered));
                        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $quotedTable, implode(', ', $quotedCols), implode(', ', $vals));
                        DB::query($sql);
                    }
                    $rowsRestored++;
                }
                $tablesRestored++;
            }

            DB::query('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            try {
                DB::query('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $ignored) {
            }
            return $this->jsonResponse([
                'success' => false,
                'error'   => 'Database restore failed: ' . $e->getMessage(),
            ], 500);
        }

        // 3. Flush caches & OPcache
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        foreach (array_merge(glob('/tmp/silverstripe-cache*') ?: [], glob(BASE_PATH . '/silverstripe-cache*') ?: []) as $cacheDir) {
            $this->deleteDirectory($cacheDir);
        }

        return $this->jsonResponse([
            'success'        => true,
            'siteName'       => $bundle['siteName'] ?? 'Site Configuration',
            'exportedAt'     => $bundle['exportedAt'] ?? '',
            'tablesRestored' => $tablesRestored,
            'rowsRestored'   => $rowsRestored,
            'assetsRestored' => $assetsRestored,
            'message'        => 'Site configuration restored! Reload the browser to see updated locations, devices, PINs, and branding.',
        ], 200);
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

        // Persist active patch metadata on target host
        $manifest['installed_at'] = gmdate('Y-m-d\TH:i:s\Z');
        @file_put_contents(
            BASE_PATH . '/app/patch-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        // Bump kiosk reload token so attached Raspberry Pi touchscreens auto-refresh immediately
        $varDir = BASE_PATH . '/var';
        if (!is_dir($varDir)) {
            @mkdir($varDir, 0775, true);
        }
        @file_put_contents($varDir . '/kiosk-reload-token.txt', (string) microtime(true));

        // 4. Reset PHP OPcache and clear SilverStripe cache directories
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        foreach (array_merge(glob('/tmp/silverstripe-cache*') ?: [], glob(BASE_PATH . '/silverstripe-cache*') ?: []) as $cacheDir) {
            $this->deleteDirectory($cacheDir);
        }

        // 5. Run sake db:build --flush via explicit CLI PHP binary to capture full build log
        $buildLog = '';
        $sakeRan = false;

        if (function_exists('exec')) {
            $phpCli = null;
            foreach (['/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
                if (is_executable($candidate)) {
                    $phpCli = $candidate;
                    break;
                }
            }
            $sakeBin = BASE_PATH . '/vendor/bin/sake';
            if ($phpCli !== null && is_file($sakeBin)) {
                $cmd = sprintf(
                    'cd %s && %s %s db:build --flush 2>&1',
                    escapeshellarg(BASE_PATH),
                    escapeshellarg($phpCli),
                    escapeshellarg($sakeBin)
                );
                $output = [];
                $exitCode = 1;
                @exec($cmd, $output, $exitCode);
                if ($exitCode === 0 && !empty($output)) {
                    $buildLog = implode("\n", $output);
                    $sakeRan = true;
                }
            }
        }

        // Fallback to in-process SilverStripe 6 DevBuildController if CLI exec is restricted
        if (!$sakeRan) {
            try {
                ClassLoader::inst()->getManifest()->regenerate(false);

                ob_start();
                if (class_exists(DevBuildController::class)) {
                    $buildReq = new HTTPRequest('GET', 'dev/build', ['flush' => 'all']);
                    $devBuild = DevBuildController::create();
                    $res = $devBuild->build($buildReq);
                    $buffered = (string) ob_get_clean();
                    $body = $res instanceof HTTPResponse ? (string) $res->getBody() : $buffered;
                    $buildLog = trim(strip_tags($body !== '' ? $body : $buffered));
                } else {
                    ob_end_clean();
                }
            } catch (Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                return $this->jsonResponse([
                    'success'      => false,
                    'version'      => $manifest['version'] ?? 'unknown',
                    'modules'      => $manifest['modules'] ?? [],
                    'filesWritten' => $written,
                    'error'        => 'Database rebuild failed: ' . $e->getMessage(),
                ], 500);
            }
        }

        return $this->jsonResponse([
            'success'      => true,
            'version'      => $manifest['version'] ?? 'unknown',
            'created'      => $manifest['created'] ?? '',
            'installedAt'  => $manifest['installed_at'],
            'modules'      => $manifest['modules'] ?? [],
            'filesWritten' => $written,
            'buildOutput'  => $buildLog !== '' ? $buildLog : 'Database schema and class manifest rebuilt.',
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

    private function jsonResponse(array $payload, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_PRETTY_PRINT), $status)
            ->addHeader('Content-Type', 'application/json');
    }
}
