<?php

namespace App\Model;

use App\Contract\TouchPanelModuleInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

class SystemModule extends DataObject
{
    private static string $table_name = 'SystemModule';

    private static array $db = [
        'Title' => 'Varchar(50)',
        'Route' => 'Varchar(100)',
        'PermissionCode' => 'Varchar(100)',
        'SortOrder' => 'Int',
    ];

    private static array $belongs_many_many = [
        'PanelSettings' => PanelSettings::class,
    ];

    private static string $default_sort = 'SortOrder ASC';

    private static array $summary_fields = [
        'Title' => 'Module Name',
        'Route' => 'URL Route',
        'PermissionCode' => 'Permission',
    ];

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        foreach (ClassInfo::implementorsOf(TouchPanelModuleInterface::class) as $class) {
            $title = $class::getModuleTitle();
            $cleanRoute = '/' . ltrim((string) $class::getModuleRoute(), '/');

            $existing = self::get()->filter('Route', $cleanRoute)->first()
                ?: self::get()->filter('Title', $title)->first();

            if (!$existing) {
                $record = self::create([
                    'Title' => $title,
                    'Route' => $cleanRoute,
                    'PermissionCode' => $class::getModulePermission(),
                    'SortOrder' => $class::getModuleSortOrder(),
                ]);
                $record->write();
                DB::alteration_message("Added System Module: {$title}", 'created');
            }
        }

        // Remove standalone Discovery module record if previously seeded
        $legacyDiscovery = self::get()->filter('Route', '/panel/discover')->first();
        if ($legacyDiscovery) {
            $legacyDiscovery->delete();
        }
    }
}
