<?php

namespace App\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\TextField;

class ViscaCamera extends DataObject
{
    private static $table_name = 'ViscaCamera';

    private static $db = array(
        'Title' => 'Varchar(255)',
        'IPAddress' => 'Varchar(45)',
        'Port' => 'Int',
        'Active' => 'Boolean(1)'
    );

    private static $has_one = array(
        'Location' => Location::class
    );

    private static $defaults = array(
        'Port' => 52381,
        'Active' => 1
    );

    private static $summary_fields = array(
        'Title' => 'Title',
        'IPAddress' => 'IP Address',
        'PortUnformatted' => 'Port',
        'Location.Title' => 'Location',
        'Active.Nice' => 'Active'
    );

    /**
     * Prevents the GridField summary from injecting a comma into the port number
     */
    public function getPortUnformatted(): string
    {
        return (string) $this->Port;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // Swap out the default NumericField for a standard TextField
        // so the CMS edit form does not format the integer with commas
        $fields->replaceField(
            'Port',
            TextField::create('Port', 'Port')
                ->setDescription('VISCA UDP Port (default 52381)')
        );

        return $fields;
    }
}
