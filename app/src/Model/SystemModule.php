<?php

namespace App\Model;

use App\Contract\TouchPanelModuleInterface;
use App\Security\VPXPermissionProvider;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

class SystemModule extends DataObject
{
    private static $table_name = 'SystemModule';

    private static $db = [
        'Title' => 'Varchar(50)',
        'Route' => 'Varchar(100)',
        'PermissionCode' => 'Varchar(100)',
        'SortOrder' => 'Int'
    ];

    private static $belongs_many_many = [
        'PanelSettings' => PanelSettings::class
    ];

    private static $default_sort = 'SortOrder ASC';

    private static $summary_fields = [
        'Title' => 'Module Name',
        'Route' => 'URL Route',
        'PermissionCode' => 'Permission'
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        $modules = [
            ['Video Matrix', '/panel/video', VPXPermissionProvider::PERM_ACCESS_MATRIX, 10],
            ['Audio DSP', '/panel/audio', VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX, 20],
            ['Lighting', '/panel/lighting', VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC, 30],
            ['Cameras', '/panel/camera', VPXPermissionProvider::PERM_ACCESS_CAMERA, 40],
            ['RØDECaster', '/panel/rodecaster', VPXPermissionProvider::PERM_ACCESS_RODECASTER, 50],
        ];

        foreach (ClassInfo::implementorsOf(TouchPanelModuleInterface::class) as $class) {
            $modules[] = [
                $class::getModuleTitle(),
                $class::getModuleRoute(),
                $class::getModulePermission(),
                $class::getModuleSortOrder(),
            ];
        }

        foreach ($modules as $mod) {
            $cleanRoute = '/' . ltrim((string) $mod[1], '/');
            $existing = self::get()->filter('Route', $cleanRoute)->first()
                ?: self::get()->filter('Title', $mod[0])->first();
            if (!$existing) {
                $record = self::create([
                    'Title' => $mod[0],
                    'Route' => $cleanRoute,
                    'PermissionCode' => $mod[2],
                    'SortOrder' => $mod[3]
                ]);
                $record->write();
                DB::alteration_message("Added System Module: {$mod[0]}", 'created');
            }
        }

        // Remove standalone Discovery module record if previously seeded
        $legacyDiscovery = self::get()->filter('Route', '/panel/discover')->first();
        if ($legacyDiscovery) {
            $legacyDiscovery->delete();
        }
    }
}
