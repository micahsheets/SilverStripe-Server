<?php

namespace App\DTX\Model;

use App\Model\Location;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class DTXDevice extends DataObject
{
    private static $table_name = 'DTXDevice';

    private static $db = [
        'Title'       => 'Varchar(255)',
        'IPAddress'   => 'Varchar(45)',
        'ControlPort' => 'Int',
        'DeviceModel' => "Enum('DTX-44,DTX-88,DTX-32,DTX-64', 'DTX-44')",
        'Active'      => 'Boolean(1)',
    ];

    private static $has_one = [
        'Location' => Location::class,
    ];

    private static $defaults = [
        'ControlPort' => 5000,
        'Active'      => 1,
    ];

    private static $summary_fields = [
        'Title'              => 'Title',
        'IPAddress'          => 'IP Address',
        'ControlPortDisplay' => 'Control Port',
        'DeviceModel'        => 'Model',
        'Location.Title'     => 'Location',
        'Active.Nice'        => 'Active',
    ];

    public function getChannelCapacity(): int
    {
        return match ((string) $this->DeviceModel) {
            'DTX-64' => 64,
            'DTX-32' => 32,
            'DTX-88' => 8,
            default  => 4,
        };
    }

    public function getControlPortDisplay(): string
    {
        return (string) $this->ControlPort;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->replaceField(
            'ControlPort',
            TextField::create('ControlPort', 'Control Port')
                ->setAttribute('type', 'number')
                ->setAttribute('inputmode', 'numeric')
                ->setAttribute('step', '1')
                ->setAttribute('min', '1')
                ->setAttribute('max', '65535')
                ->setDescription('UDP/TCP control port (default: 5000)')
        );

        return $fields;
    }
}
