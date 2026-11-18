<?php

namespace App\Service;

use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Model\SystemModule;
use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;
use ZipArchive;

class SystemBackupService
{
    use Injectable;

    public function getDiscoverableModules(): array
    {
        $activeRoutes = [];
        $settings = PanelSettings::current();
        foreach ($settings->ActiveModules() as $mod) {
            $activeRoutes['/' . ltrim((string) $mod->Route, '/')] = true;
        }

        $moduleRoutes = [];
        $moduleTitles = [
            'Core' => 'Core Panel Settings, Branding & Locations',
        ];
        foreach (ClassInfo::implementorsOf(TouchPanelModuleInterface::class) as $ctrlClass) {
            $parts = explode('\\', $ctrlClass);
            if (count($parts) >= 3 && $parts[0] === 'App') {
                $modKey = $parts[1];
                $moduleRoutes[$modKey] = '/' . ltrim((string) $ctrlClass::getModuleRoute(), '/');
                $moduleTitles[$modKey] = $ctrlClass::getModuleTitle() . " ({$modKey})";
            }
        }

        $modules = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if (!str_starts_with($class, 'App\\')) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $tableName = DataObject::getSchema()->tableName($class);
            if (!$tableName) {
                continue;
            }

            $parts = explode('\\', $class);
            $modKey = ($parts[1] === 'Model') ? 'Core' : $parts[1];

            if (!isset($modules[$modKey])) {
                $route = $moduleRoutes[$modKey] ?? null;
                $inUse = ($modKey === 'Core') || ($route !== null && isset($activeRoutes[$route]));
                $modules[$modKey] = [
                    'key'     => $modKey,
                    'label'   => $moduleTitles[$modKey] ?? $modKey,
                    'inUse'   => $inUse,
                    'classes' => [],
                    'records' => 0,
                ];
            }

            $count = (int) $class::get()->count();
            $modules[$modKey]['classes'][] = $class;
            $modules[$modKey]['records'] += $count;
        }

        uksort($modules, static function (string $a, string $b): int {
            if ($a === 'Core') return -1;
            if ($b === 'Core') return 1;
            return strcmp($a, $b);
        });

        return $modules;
    }

    private function getClassHasOnes(string $class): array
    {
        $raw = Config::inst()->get($class, 'has_one');
        return is_array($raw) ? $raw : [];
    }

    private function getClassManyManys(string $class): array
    {
        $raw = Config::inst()->get($class, 'many_many');
        return is_array($raw) ? $raw : [];
    }

    public function createBackupZip(array $selectedModuleKeys, bool $includeAssets = true): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is not enabled in the container.');
        }

        $discovered = $this->getDiscoverableModules();
        $selectedModuleKeys = array_values(array_intersect(array_keys($discovered), $selectedModuleKeys));
        if (empty($selectedModuleKeys)) {
            throw new RuntimeException('Please select at least one module to back up.');
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'av_backup_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create temporary backup zip archive.');
        }

        $manifest = [
            'version'   => '1.0',
            'createdAt' => date('c'),
            'host'      => $_SERVER['HTTP_HOST'] ?? gethostname(),
            'modules'   => [],
            'assets'    => [],
        ];

        $schema = DataObject::getSchema();
        $fileIdsToBundle = [];

        foreach ($selectedModuleKeys as $modKey) {
            $modInfo = $discovered[$modKey];
            $orderedClasses = $this->sortClassesByDependency($modInfo['classes']);
            $modSummary = [
                'label'   => $modInfo['label'],
                'classes' => [],
            ];

            foreach ($orderedClasses as $class) {
                $dbFields = array_keys($schema->databaseFields($class));
                $hasOnes = $this->getClassHasOnes($class);
                $manyManys = $this->getClassManyManys($class);

                $rows = [];
                foreach ($class::get() as $obj) {
                    $row = [
                        'ID'       => (int) $obj->ID,
                        'fields'   => [],
                        'hasOne'   => [],
                        'manyMany' => [],
                    ];

                    foreach ($dbFields as $field) {
                        if (in_array($field, ['ID', 'ClassName'], true)) {
                            continue;
                        }
                        $row['fields'][$field] = $obj->getField($field);
                    }

                    foreach ($hasOnes as $relName => $targetClass) {
                        $fkCol = $relName . 'ID';
                        $fkVal = (int) $obj->getField($fkCol);
                        $row['hasOne'][$relName] = [
                            'targetClass' => is_string($targetClass) ? $targetClass : '',
                            'id'          => $fkVal,
                        ];
                        if ($fkVal > 0 && is_string($targetClass) && is_a($targetClass, File::class, true)) {
                            $fileIdsToBundle[$fkVal] = true;
                        }
                    }

                    foreach ($manyManys as $relName => $spec) {
                        $list = $obj->$relName();
                        if ($list instanceof ManyManyList) {
                            $row['manyMany'][$relName] = array_map('intval', $list->column('ID'));
                        }
                    }

                    $rows[] = $row;
                }

                $shortClass = substr(strrchr($class, '\\'), 1);
                $entryPath = "data/{$modKey}/{$shortClass}.json";
                $zip->addFromString($entryPath, json_encode([
                    'class'   => $class,
                    'module'  => $modKey,
                    'records' => $rows,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $modSummary['classes'][] = [
                    'class' => $class,
                    'file'  => $entryPath,
                    'count' => count($rows),
                ];
            }

            $manifest['modules'][$modKey] = $modSummary;
        }

        if ($includeAssets && !empty($fileIdsToBundle)) {
            foreach (array_keys($fileIdsToBundle) as $fileId) {
                $file = File::get()->byID((int) $fileId);
                if ($file && $file->exists()) {
                    $stream = $file->getStream();
                    if (is_resource($stream)) {
                        $contents = stream_get_contents($stream);
                        fclose($stream);
                        $filename = basename((string) $file->getFilename()) ?: ("asset_{$fileId}.bin");
                        $assetZipPath = "assets/{$fileId}_{$filename}";
                        $zip->addFromString($assetZipPath, $contents);
                        $manifest['assets'][(string) $fileId] = [
                            'oldId'    => (int) $fileId,
                            'filename' => $filename,
                            'title'    => (string) $file->Title,
                            'zipPath'  => $assetZipPath,
                        ];
                    }
                }
            }
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $tmpZip;
    }

    public function inspectBackupZip(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Invalid or corrupted ZIP archive.');
        }
        $rawManifest = $zip->getFromName('manifest.json');
        $zip->close();

        if (!is_string($rawManifest) || $rawManifest === '') {
            throw new RuntimeException('Archive is missing manifest.json — not a valid AV Control backup.');
        }

        $manifest = json_decode($rawManifest, true);
        if (!is_array($manifest) || !isset($manifest['modules'])) {
            throw new RuntimeException('Corrupted manifest.json inside backup archive.');
        }

        return $manifest;
    }

    public function restoreBackupZip(string $zipPath, ?array $onlyModules = null, string $mode = 'replace'): array
    {
        $manifest = $this->inspectBackupZip($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open backup ZIP archive.');
        }

        $availableModules = array_keys($manifest['modules']);
        $targetModules = ($onlyModules !== null && !empty($onlyModules))
            ? array_values(array_intersect($availableModules, $onlyModules))
            : $availableModules;

        if (empty($targetModules)) {
            $zip->close();
            throw new RuntimeException('None of the selected modules exist in this backup file.');
        }

        $idMap = [];
        $stats = [
            'modulesRestored' => [],
            'recordsRestored' => 0,
            'assetsRestored'  => 0,
        ];

        if (!empty($manifest['assets']) && in_array('Core', $targetModules, true)) {
            $folder = Folder::find_or_make('av-branding');
            foreach ($manifest['assets'] as $oldIdStr => $assetMeta) {
                $bin = $zip->getFromName((string) ($assetMeta['zipPath'] ?? ''));
                if (!is_string($bin)) {
                    continue;
                }
                $filename = basename((string) ($assetMeta['filename'] ?? "restored_{$oldIdStr}.png"));
                $fileObj = Image::create();
                $fileObj->setFromString($bin, 'av-branding/' . $filename);
                $fileObj->ParentID = (int) $folder->ID;
                $fileObj->Title = (string) ($assetMeta['title'] ?? $filename);
                $fileObj->write();
                $fileObj->publishSingle();

                $idMap[File::class][(int) $oldIdStr] = (int) $fileObj->ID;
                $idMap[Image::class][(int) $oldIdStr] = (int) $fileObj->ID;
                $stats['assetsRestored']++;
            }
        }

        $classPayloads = [];
        foreach ($targetModules as $modKey) {
            $modEntry = $manifest['modules'][$modKey] ?? [];
            foreach (($modEntry['classes'] ?? []) as $clsMeta) {
                $class = (string) ($clsMeta['class'] ?? '');
                $file = (string) ($clsMeta['file'] ?? '');
                if ($class === '' || !class_exists($class)) {
                    continue;
                }
                $rawJson = $zip->getFromName($file);
                if (!is_string($rawJson)) {
                    continue;
                }
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded) && isset($decoded['records'])) {
                    $classPayloads[$class] = $decoded['records'];
                }
            }
            $stats['modulesRestored'][] = $modKey;
        }
        $zip->close();

        $orderedClasses = $this->sortClassesByDependency(array_keys($classPayloads));
        $schema = DataObject::getSchema();

        if ($mode === 'replace') {
            foreach (array_reverse($orderedClasses) as $class) {
                if ($class === SystemModule::class) {
                    continue;
                }
                foreach ($class::get() as $existing) {
                    $existing->delete();
                }
            }
        }

        $pendingManyMany = [];
        foreach ($orderedClasses as $class) {
            $records = $classPayloads[$class] ?? [];
            $dbFields = array_keys($schema->databaseFields($class));

            foreach ($records as $row) {
                $oldId = (int) ($row['ID'] ?? 0);
                $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];

                $obj = null;
                if ($class === PanelSettings::class) {
                    $obj = PanelSettings::current();
                } elseif ($class === SystemModule::class && !empty($fields['Route'])) {
                    $obj = SystemModule::get()->filter('Route', $fields['Route'])->first();
                } elseif ($mode === 'merge' && $oldId > 0) {
                    $obj = $class::get()->byID($oldId);
                }

                if (!$obj) {
                    $obj = $class::create();
                }

                foreach ($fields as $k => $v) {
                    if (in_array($k, $dbFields, true) && !in_array($k, ['ID', 'ClassName'], true)) {
                        $obj->setField($k, $v);
                    }
                }

                foreach (($row['hasOne'] ?? []) as $relName => $relInfo) {
                    $fkCol = $relName . 'ID';
                    $oldTargetId = (int) ($relInfo['id'] ?? 0);
                    $targetClass = (string) ($relInfo['targetClass'] ?? '');
                    if ($oldTargetId <= 0) {
                        $obj->setField($fkCol, 0);
                        continue;
                    }
                    $mappedId = $this->resolveMappedId($targetClass, $oldTargetId, $idMap);
                    $obj->setField($fkCol, $mappedId);
                }

                $obj->write();
                if ($oldId > 0) {
                    $idMap[$class][$oldId] = (int) $obj->ID;
                }
                $stats['recordsRestored']++;

                if (!empty($row['manyMany'])) {
                    $pendingManyMany[] = [
                        'class'    => $class,
                        'newId'    => (int) $obj->ID,
                        'manyMany' => $row['manyMany'],
                    ];
                }
            }
        }

        foreach ($pendingManyMany as $item) {
            $class = $item['class'];
            $obj = $class::get()->byID($item['newId']);
            if (!$obj) {
                continue;
            }
            $manyManySpecs = $this->getClassManyManys($class);
            foreach ($item['manyMany'] as $relName => $oldTargetIds) {
                if (!isset($manyManySpecs[$relName])) {
                    continue;
                }
                $spec = $manyManySpecs[$relName];
                $targetClass = is_array($spec) ? ($spec['through'] ?? '') : (string) $spec;
                $list = $obj->$relName();
                if ($list instanceof ManyManyList) {
                    $newIds = [];
                    foreach ((array) $oldTargetIds as $oldTid) {
                        $resolved = $this->resolveMappedId($targetClass, (int) $oldTid, $idMap);
                        if ($resolved > 0) {
                            $newIds[] = $resolved;
                        }
                    }
                    $list->setByIDList($newIds);
                }
            }
        }

        SystemModule::singleton()->requireDefaultRecords();

        return $stats;
    }

    private function resolveMappedId(string $targetClass, int $oldId, array $idMap): int
    {
        if (isset($idMap[$targetClass][$oldId])) {
            return (int) $idMap[$targetClass][$oldId];
        }
        foreach ($idMap as $mappedClass => $map) {
            if ($targetClass !== '' && (is_a($mappedClass, $targetClass, true) || is_a($targetClass, $mappedClass, true))) {
                if (isset($map[$oldId])) {
                    return (int) $map[$oldId];
                }
            }
        }
        if ($targetClass !== '' && class_exists($targetClass) && $targetClass::get()->byID($oldId)) {
            return $oldId;
        }
        return 0;
    }

    private function sortClassesByDependency(array $classes): array
    {
        $classSet = array_flip($classes);
        $deps = [];

        foreach ($classes as $cls) {
            $deps[$cls] = [];
            foreach ($this->getClassHasOnes($cls) as $targetClass) {
                if (is_string($targetClass) && $targetClass !== $cls && isset($classSet[$targetClass])) {
                    $deps[$cls][] = $targetClass;
                }
            }
        }

        $sorted = [];
        $visited = [];
        $visit = static function (string $node) use (&$visit, &$sorted, &$visited, $deps): void {
            if (isset($visited[$node])) {
                return;
            }
            $visited[$node] = true;
            foreach ($deps[$node] ?? [] as $dep) {
                $visit($dep);
            }
            $sorted[] = $node;
        };

        foreach ($classes as $cls) {
            $visit($cls);
        }

        return $sorted;
    }
}
