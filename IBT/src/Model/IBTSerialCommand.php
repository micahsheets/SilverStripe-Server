<?php

namespace App\IBT\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class IBTSerialCommand extends DataObject
{
    private static string $table_name = 'IBTSerialCommand';
    private static string $singular_name = 'IBT RS-232 Command';
    private static string $plural_name = 'IBT RS-232 Commands';
    private static string $default_sort = 'SortOrder ASC, ID ASC';

    private static array $db = [
        'Title'     => 'Varchar(80)',
        'Command'   => 'Varchar(255)',
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        'Device' => IBTDevice::class,
    ];

    private static array $summary_fields = [
        'Title'     => 'Button Label',
        'Command'   => 'RS-232 Command String',
        'SortOrder' => 'Sort Order',
    ];

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TextField::create('Title', 'Button Label'),
            TextField::create('Command', 'IBT RS-232 Command String'),
            NumericField::create('SortOrder', 'Sort Order')->setHTML5(true)
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') $result->addFieldError('Title', 'Button Label is required.');
        if (trim((string) $this->Command) === '') $result->addFieldError('Command', 'RS-232 Command String is required.');
        return $result;
    }
}
