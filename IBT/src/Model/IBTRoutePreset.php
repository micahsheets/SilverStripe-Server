<?php

namespace App\IBT\Model;

use App\Model\Location;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class IBTRoutePreset extends DataObject
{
    private static string $table_name = 'IBTRoutePreset';
    private static string $singular_name = 'IBT Scene Preset';
    private static string $plural_name = 'IBT Scene Presets';
    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title'       => 'Varchar(100)',
        'Description' => 'Text',
        'SortOrder'   => 'Int',
    ];

    private static array $has_one = [
        'Location' => Location::class,
    ];

    private static array $has_many = [
        'Actions' => IBTRoutePresetAction::class . '.Preset',
    ];

    private static array $summary_fields = [
        'Title'          => 'Preset Name',
        'Location.Title' => 'Room / Scope',
        'Actions.Count'  => 'Routing Steps',
        'Description'    => 'Notes',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(
            TextField::create('Title', 'Preset Name'),
            DropdownField::create('LocationID', 'Location / Room Scope', Location::get()->map('ID', 'Title'))
                ->setEmptyString('-- Global / All Locations --'),
            TextareaField::create('Description', 'Description / Notes'),
            NumericField::create('SortOrder', 'Sort Order')->setHTML5(true)
        );
        if ($this->isInDB()) {
            $fields->push(GridField::create('Actions', 'Device Routing Actions', $this->Actions(), GridFieldConfig_RecordEditor::create()));
        }
        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') $result->addFieldError('Title', 'Please enter a Preset Name.');
        return $result;
    }
}
