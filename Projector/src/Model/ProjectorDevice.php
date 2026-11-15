<?php

namespace App\Projector\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataObject;

class ProjectorDevice extends DataObject
{
    private static string $table_name = 'ProjectorDevice';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'IPAddress' => 'Varchar(45)',
        'Port' => 'Int',
        'Active' => 'Boolean',
    ];

    private static array $defaults = [
        'Port' => 4352, // PJLink default TCP port
        'Active' => 1,
    ];

    private static array $summary_fields = [
        'Title' => 'Projector Name',
        'IPAddress' => 'IP Address',
        'RawPort' => 'Port',
        'Active.Nice' => 'Active',
    ];

    public function getRawPort(): string
    {
        return (string) $this->Port;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $portField = NumericField::create('Port', 'Port')
            ->setHTML5(true);

        // Disable thousands separator (e.g., 4,352 -> 4352)
        if (method_exists($portField, 'setUseGrouping')) {
            $portField->setUseGrouping(false);
        }

        $fields->replaceField('Port', $portField);

        return $fields;
    }
}
