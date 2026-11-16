<?php

namespace App\VPX\Model;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\DataObject;

/**
 * @property bool $PresetsOnlyMode
 */
class VPXSettings extends DataObject
{
    private static string $table_name = 'VPXSettings';

    private static string $singular_name = 'Matrix Settings';

    private static string $plural_name = 'Matrix Settings';

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
        return 'Video Matrix Display Settings';
    }

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    CheckboxField::create(
                        'PresetsOnlyMode',
                        'Presets Only Mode (Hide Display & Source Matrix Panels)'
                    )->setDescription(
                        'When enabled, the Video Matrix touch screen hides the Display and Source routing columns and expands the Scene Preset buttons to fill the screen.'
                    )
                )
            )
        );
    }

    public function canCreate($member = null, $context = []): bool
    {
        return self::get()->count() === 0 && parent::canCreate($member, $context);
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
