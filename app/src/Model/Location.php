<?php

namespace App\Model;

use SilverStripe\Core\Validation\ValidationResult;
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
 * @method HasManyList<VPXDevice> Devices()
 */
class Location extends DataObject
{
    private static string $table_name = 'Location';

    private static string $singular_name = 'Location';

    private static string $plural_name = 'Locations';

    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Description' => 'Text',
        'SortOrder' => 'Int',
    ];

    private static array $summary_fields = [
        'Title' => 'Location / Room Name',
        'Description' => 'Notes',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(
            TextField::create('Title', 'Location / Room Name')
                ->setDescription('e.g., "Front Room", "Back Wall", or "Main Stage"'),
            TextareaField::create('Description', 'Description / Notes')
        );

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (empty(trim((string) $this->Title))) {
            $result->addFieldError('Title', 'Please enter a Location or Room name.');
        }

        return $result;
    }
}
