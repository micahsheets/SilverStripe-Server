<?php

namespace App\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * @property string $Title
 * @property string|null $Description
 * @property int $SortOrder
 *
 * @property int $LocationID
 *
 * @method Location Location()
 * @method HasManyList<RoutePresetAction> Actions()
 */
class RoutePreset extends DataObject
{
    private static string $table_name = 'RoutePreset';

    private static string $singular_name = 'Route Preset';

    private static string $plural_name = 'Route Presets';

    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Description' => 'Text',
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        // Optional: tie a preset to a specific Room/Location, or leave blank for system-wide presets
        'Location' => Location::class,
    ];

    private static array $has_many = [
        'Actions' => RoutePresetAction::class . '.Preset',
    ];

    private static array $summary_fields = [
        'Title' => 'Preset Name',
        'Location.Title' => 'Room / Scope',
        'Actions.Count' => 'Routing Steps',
        'Description' => 'Notes',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(
            TextField::create('Title', 'Preset Name')
                ->setDescription('UI button label (e.g., "Presentation Mode" or "All Displays - Apple TV")'),
            DropdownField::create('LocationID', 'Location / Room Scope', Location::get()->map('ID', 'Title'))
                ->setEmptyString('-- Global / All Locations --')
                ->setDescription('Optional: assign this preset to a specific room UI'),
            TextareaField::create('Description', 'Description / Notes')
        );

        if ($this->isInDB()) {
            $fields->push(
                GridField::create(
                    'Actions',
                    'Device Routing Actions',
                    $this->Actions(),
                    GridFieldConfig_RecordEditor::create()
                )
            );
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (empty(trim((string) $this->Title))) {
            $result->addFieldError('Title', 'Please enter a Preset Name.');
        }

        return $result;
    }
}
