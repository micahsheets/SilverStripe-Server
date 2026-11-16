<?php

namespace App\Enttec\Model;

use App\Model\Location;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property string $IPAddress
 * @property int $UDPPort
 * @property string $BlackoutCommand
 * @property string $Model
 * @property int $Universes
 * @property string $SlotMetadata
 * @property int $LocationID
 *
 * @method Location Location()
 */
class EnttecDevice extends DataObject
{
    private static string $table_name = 'EnttecDevice';

    private static string $singular_name = 'Enttec S-Play Controller';

    private static string $plural_name = 'Enttec S-Play Controllers';

    public const MODEL_SP1 = 'SP1';
    public const MODEL_MINI = 'Mini';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'IPAddress' => 'Varchar(45)',
        'UDPPort' => 'Int',
        'BlackoutCommand' => 'Varchar(100)',
        'Model' => "Enum('SP1,Mini', 'SP1')",
        'Universes' => 'Int',
        'SlotMetadata' => 'Text',
    ];

    private static array $defaults = [
        'UDPPort' => 5000,
        'BlackoutCommand' => 'stop',
        'Model' => 'SP1',
        'Universes' => 4,
    ];

    private static array $has_one = [
        'Location' => Location::class,
    ];

    private static array $summary_fields = [
        'Title' => 'Controller Name',
        'Location.Title' => 'Room / Zone',
        'IPAddress' => 'IP Address',
        'UDPPortDisplay' => 'UDP Port',
        'Model' => 'Hardware Model',
        'Universes' => 'Universes',
    ];

    public function getUDPPortDisplay(): string
    {
        return (string) $this->UDPPort;
    }

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TextField::create('Title', 'Controller Name')
                ->setDescription('Example: Main Auditorium S-Play'),
            DropdownField::create('LocationID', 'Room / Zone', Location::get()->map('ID', 'Title'))
                ->setEmptyString('-- Global / All Rooms --'),
            TextField::create('IPAddress', 'S-Play IP Address')
                ->setAttribute('placeholder', '192.168.1.50'),
            TextField::create('UDPPort', 'UDP Control Port')
                ->setAttribute('type', 'number')
                ->setAttribute('inputmode', 'numeric')
                ->setAttribute('step', '1')
                ->setAttribute('min', '1')
                ->setAttribute('max', '65535')
                ->setDescription('UDP port for direct commands (default: 5000).'),
            TextField::create('BlackoutCommand', 'Blackout UDP Command')
                ->setAttribute('placeholder', 'stop')
                ->setDescription('UDP command string sent when the Blackout button is pressed (default: stop).'),
            DropdownField::create('Model', 'Hardware Model', [
                self::MODEL_SP1 => 'Enttec S-Play SP1 (Full Playlists, Create & Delete Enabled)',
                self::MODEL_MINI => 'Enttec S-Play Mini (16 Fixed Slots, Overwrite Only)',
            ])->setDescription('Determines slot limits and whether playlist creation/deletion is enabled.'),
            NumericField::create('Universes', 'Available Universes')
                ->setDescription('Number of universes configured on this device (e.g., 1, 2, or 4).')
        );
    }

    public function isMini(): bool
    {
        return $this->Model === self::MODEL_MINI;
    }

    public function getSlotMetadataMap(): array
    {
        if (empty($this->SlotMetadata)) {
            return array();
        }
        $decoded = json_decode((string) $this->SlotMetadata, true);
        return is_array($decoded) ? $decoded : array();
    }

    public function saveSlotMetadata(int $playlistId, int $sourceType, array $universes): void
    {
        $map = $this->getSlotMetadataMap();
        $map[(string) $playlistId] = [
            'source' => $sourceType,
            'universes' => array_values(array_map('intval', $universes)),
        ];
        $this->SlotMetadata = json_encode($map);
        $this->write();
    }
}
