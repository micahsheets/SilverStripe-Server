<?php

namespace App\Admin;

use App\Service\SystemBackupService;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;
use Throwable;

class SystemBackupAdmin extends LeftAndMain
{
    private static string $url_segment = 'system-backup';
    private static string $menu_title = 'Backup & Restore';
    private static string $menu_icon_class = 'font-icon-sync';
    private static int $menu_priority = 0;

    private static array $url_handlers = [
        'export'          => 'export',
        'inspect'         => 'inspect',
        'restore'         => 'restore',
        'snapshots'       => 'snapshots',
        'snapshot-save'   => 'snapshotSave',
        'snapshot-update' => 'snapshotUpdate',
        'snapshot-load'   => 'snapshotLoad',
        'snapshot-base'   => 'snapshotBase',
        'snapshot-delete' => 'snapshotDelete',
        ''                => 'index',
    ];

    private static array $allowed_actions = [
        'export',
        'inspect',
        'restore',
        'snapshots',
        'snapshotSave',
        'snapshotUpdate',
        'snapshotLoad',
        'snapshotBase',
        'snapshotDelete',
    ];

    public function canView($member = null)
    {
        return Permission::check('ADMIN', 'any', $member)
            || Permission::check('CMS_ACCESS_App\Admin\SystemBackupAdmin', 'any', $member);
    }

    public function getDiscoverableModulesList(): ArrayList
    {
        $list = ArrayList::create();
        foreach (SystemBackupService::singleton()->getDiscoverableModules() as $mod) {
            $list->push(ArrayData::create([
                'Key'     => $mod['key'],
                'Label'   => $mod['label'],
                'InUse'   => $mod['inUse'],
                'Records' => $mod['records'],
                'Models'  => implode(', ', array_map(fn($c) => substr(strrchr($c, '\\'), 1), $mod['classes'])),
            ]));
        }
        return $list;
    }

    public function getSavedSnapshotsList(): ArrayList
    {
        $list = ArrayList::create();
        foreach (SystemBackupService::singleton()->listNamedSnapshots() as $snap) {
            $list->push(ArrayData::create([
                'ID'           => $snap['id'],
                'Name'         => $snap['name'],
                'CreatedAt'    => date('Y-m-d H:i', strtotime((string) $snap['createdAt']) ?: time()),
                'ModulesText'  => implode(', ', (array) ($snap['modules'] ?? [])),
                'TotalRecords' => (int) ($snap['totalRecords'] ?? 0),
                'AssetsCount'  => (int) ($snap['assetsCount'] ?? 0),
                'SizeKB'       => (int) ($snap['sizeKB'] ?? 1),
                'IsBaseline'   => !empty($snap['isBaseline']),
                'IsActive'     => !empty($snap['isActive']),
            ]));
        }
        return $list;
    }

    public function getDefaultBaselineSnapshot(): ?ArrayData
    {
        foreach ($this->getSavedSnapshotsList() as $snap) {
            if ($snap->IsBaseline) {
                return $snap;
            }
        }
        return null;
    }

    public function getCsrfToken(): string
    {
        return SecurityToken::inst()->getValue();
    }

    public function snapshots(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized'], 403);
        }
        $svc = SystemBackupService::singleton();
        return $this->jsonResponse([
            'success'    => true,
            'baselineId' => $svc->getDefaultBaselineId(),
            'activeId'   => $svc->getActiveSnapshotId(),
            'snapshots'  => $svc->listNamedSnapshots(),
        ]);
    }

    public function snapshotSave(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canView()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized or invalid method.'], 403);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $name = trim((string) $request->postVar('snapshot_name'));
            $selected = $request->postVar('modules');
            $modules = is_array($selected) ? $selected : [];
            $includeAssets = (bool) ($request->postVar('include_assets') ?? true);
            $setAsBaseline = (bool) ($request->postVar('set_as_baseline') ?? false);

            $svc = SystemBackupService::singleton();
            $meta = $svc->saveNamedSnapshot($name, $modules, $includeAssets, $setAsBaseline);

            return $this->jsonResponse([
                'success'    => true,
                'snapshot'   => $meta,
                'baselineId' => $svc->getDefaultBaselineId(),
                'activeId'   => $svc->getActiveSnapshotId(),
                'snapshots'  => $svc->listNamedSnapshots(),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function snapshotUpdate(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canView()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized or invalid method.'], 403);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $id = trim((string) $request->postVar('snapshot_id'));
            $selected = $request->postVar('modules');
            $modules = is_array($selected) ? $selected : [];
            $includeAssets = (bool) ($request->postVar('include_assets') ?? true);

            $svc = SystemBackupService::singleton();
            $meta = $svc->overwriteNamedSnapshot($id, $modules, $includeAssets);

            return $this->jsonResponse([
                'success'    => true,
                'snapshot'   => $meta,
                'baselineId' => $svc->getDefaultBaselineId(),
                'activeId'   => $svc->getActiveSnapshotId(),
                'snapshots'  => $svc->listNamedSnapshots(),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function snapshotLoad(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canView()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized or invalid method.'], 403);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $id = trim((string) $request->postVar('snapshot_id'));
            $mode = ($request->postVar('restore_mode') === 'merge') ? 'merge' : 'replace';
            $svc = SystemBackupService::singleton();
            $stats = $svc->restoreNamedSnapshot($id, $mode);

            return $this->jsonResponse([
                'success'    => true,
                'stats'      => $stats,
                'baselineId' => $svc->getDefaultBaselineId(),
                'activeId'   => $svc->getActiveSnapshotId(),
                'snapshots'  => $svc->listNamedSnapshots(),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function snapshotBase(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canView()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized or invalid method.'], 403);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $id = trim((string) $request->postVar('snapshot_id'));
            $svc = SystemBackupService::singleton();
            $svc->setDefaultBaseline($id);

            return $this->jsonResponse([
                'success'    => true,
                'baselineId' => $svc->getDefaultBaselineId(),
                'activeId'   => $svc->getActiveSnapshotId(),
                'snapshots'  => $svc->listNamedSnapshots(),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function snapshotDelete(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canView()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthorized or invalid method.'], 403);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        try {
            $id = trim((string) $request->postVar('snapshot_id'));
            $svc = SystemBackupService::singleton();
            $svc->deleteNamedSnapshot($id);

            return $this->jsonResponse([
                'success'    => true,
                'baselineId' => $svc->getDefaultBaselineId(),
                'activeId'   => $svc->getActiveSnapshotId(),
                'snapshots'  => $svc->listNamedSnapshots(),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function export(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('Forbidden', 403);
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
            return HTTPResponse::create('Backup export failed: ' . $e->getMessage(), 500);
        }
    }

    public function inspect(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !$this->canView()) {
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
        if (!$request->isPOST() || !$this->canView()) {
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
