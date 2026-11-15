<?php

namespace App\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property string $IPAddress
 * @property int $Port
 * @property int $SceneCount
 * @property int $InputCount
 * @property int $OverlayCount
 * @property int $MediaCount
 * @property int $SortOrder
 */
class RodeCasterDevice extends DataObject
{
    private static string $table_name = 'RodeCasterDevice';

    private static string $singular_name = 'RØDECaster Video';
    private static string $plural_name = 'RØDECaster Videos';

    private static array $db = [
        'Title'        => 'Varchar(100)',
        'IPAddress'    => 'Varchar(45)',
        'Port'         => 'Int',
        'SceneCount'   => 'Int',
        'InputCount'   => 'Int',
        'OverlayCount' => 'Int',
        'MediaCount'   => 'Int',
        'SortOrder'    => 'Int',
    ];

    private static array $defaults = [
        'Port'         => 10024,
        'SceneCount'   => 6,
        'InputCount'   => 6,
        'OverlayCount' => 6,
        'MediaCount'   => 6,
    ];

    private static array $summary_fields = [
        'Title'       => 'Name',
        'IPAddress'   => 'IP Address',
        'PortDisplay' => 'OSC TCP Port',
    ];

    private static string $default_sort = 'SortOrder ASC, Title ASC';

    public function getPortDisplay(): string
    {
        return (string) $this->Port;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName(['SceneLabels', 'InputLabels', 'OverlayLabels', 'MediaLabels', 'SortOrder']);

        $fields->replaceField(
            'Port',
            TextField::create('Port', 'OSC TCP Port')
                ->setAttribute('type', 'number')
                ->setAttribute('inputmode', 'numeric')
                ->setAttribute('step', '1')
                ->setAttribute('min', '1')
                ->setAttribute('max', '65535')
                ->setDescription('OSC TCP Port (default: 10024)')
        );

        return $fields;
    }
}
