<?php

namespace App\Model;

use SilverStripe\ORM\DataObject;

class DTXDevice extends DataObject
{
    private static $table_name = 'DTXDevice';

    private static $db = array(
        'Title' => 'Varchar(255)',
        'IPAddress' => 'Varchar(45)',
        'ControlPort' => 'Int',
        'DeviceModel' => "Enum('DTX-44,DTX-88', 'DTX-44')",
        'Active' => 'Boolean(1)'
    );

    private static $has_one = array(
        'Location' => Location::class
    );

    private static $defaults = array(
        'ControlPort' => 5000,
        'Active' => 1
    );

    private static $summary_fields = array(
        'Title' => 'Title',
        'IPAddress' => 'IP Address',
        'DeviceModel' => 'Model',
        'Location.Title' => 'Location',
        'Active.Nice' => 'Active'
    );

}
