<?php

namespace App\Model;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property string $IPAddress
 * @property string $Model
 * @property int $Universes
 * @property int $BlackoutPlaylistID
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
        'Model' => "Enum('SP1,Mini', 'SP1')",
        'Universes' => 'Int',
        'BlackoutPlaylistID' => 'Int',
    ];

    private static array $defaults = [
        'Model' => 'SP1',
        'Universes' => 4,
        'BlackoutPlaylistID' => 0,
    ];

    private static array $has_one = [
        'Location' => Location::class,
    ];

    private static array $summary_fields = [
        'Title' => 'Controller Name',
        'Location.Title' => 'Room / Zone',
        'IPAddress' => 'IP Address',
        'Model' => 'Hardware Model',
        'Universes' => 'Universes',
    ];

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TextField::create('Title', 'Controller Name')
                ->setDescription('Example: Main Auditorium S-Play'),
            DropdownField::create('LocationID', 'Room / Zone', Location::get()->map('ID', 'Title'))
                ->setEmptyString('-- Global / All Rooms --'),
            TextField::create('IPAddress', 'S-Play IP Address')
                ->setAttribute('placeholder', '192.168.1.50'),
            DropdownField::create('Model', 'Hardware Model', [
                self::MODEL_SP1 => 'Enttec S-Play SP1 (Full Playlists, Create & Delete Enabled)',
                self::MODEL_MINI => 'Enttec S-Play Mini (16 Fixed Slots 0-15, Overwrite Only)',
            ])->setDescription('Determines slot limits and whether playlist creation/deletion is enabled.'),
            NumericField::create('Universes', 'Available Universes')
                ->setDescription('Number of universes licensed/configured on this device (e.g., 1, 2, or 4).'),
            NumericField::create('BlackoutPlaylistID', 'Blackout Playlist Slot ID')
                ->setDescription('Slot 0 is reserved for Blackout by default and maps to the static Blackout button.')
        );
    }

    public function isMini(): bool
    {
        return $this->Model === self::MODEL_MINI;
    }
}
