<?php

namespace App\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use App\Security\VPXPermissionProvider;

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

        $defaultModules = array(
            array('Video Matrix', '/panel', VPXPermissionProvider::PERM_ACCESS_MATRIX, 10),
            array('Audio DSP', '/panel/audio', VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX, 20),
            array('Lighting', '/panel/lighting', VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC, 30),
            array('Cameras', '/panel/camera', VPXPermissionProvider::PERM_ACCESS_CAMERA, 40),
            array('RØDECaster', '/panel/rodecaster', VPXPermissionProvider::PERM_ACCESS_RODECASTER, 50),
        );

        foreach ($defaultModules as $mod) {
            $existing = self::get()->filter('Route', $mod[1])->first()
                ?: self::get()->filter('Title', $mod[0])->first();
            if (!$existing) {
                $record = self::create(array(
                    'Title' => $mod[0],
                    'Route' => $mod[1],
                    'PermissionCode' => $mod[2],
                    'SortOrder' => $mod[3]
                ));
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
