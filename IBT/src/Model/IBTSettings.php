<?php

namespace App\IBT\Model;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\DataObject;

class IBTSettings extends DataObject
{
    private static string $table_name = 'IBTSettings';
    private static string $singular_name = 'IBT Matrix Settings';
    private static string $plural_name = 'IBT Matrix Settings';

    private static array $db = [
        'PresetsOnlyMode' => 'Boolean(0)',
    ];

    private static array $summary_fields = [
        'PresetsOnlyMode.Nice' => 'Presets Only Mode',
    ];

    public static function current(): self
    {
        $settings = self::get()->first();
        if (!$settings) {
            $settings = self::create();
            $settings->write();
        }
        return $settings;
    }

    public function getTitle(): string
    {
        return 'IBT-1-BX Matrix Display Settings';
    }

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    CheckboxField::create('PresetsOnlyMode', 'Presets Only Mode (Hide Display & Source Matrix Panels)')
                )
            )
        );
    }

    public function canCreate($member = null, $context = []): bool { return self::get()->count() === 0 && parent::canCreate($member, $context); }
    public function canDelete($member = null): bool { return false; }
}
