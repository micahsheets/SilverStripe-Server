<?php

namespace App\P300\Model;

use App\Model\Location;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property string $IPAddress
 * @property int $Port
 * @property string $VisibleChannels
 * @property int $PresetCount
 * @property int $SortOrder
 * @property int $LocationID
 *
 * @method Location Location()
 */
class P300Device extends DataObject
{
    private static string $table_name = 'P300Device';

    private static string $singular_name = 'Shure P300 DSP';

    private static string $plural_name = 'Shure P300 DSPs';

    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title'           => 'Varchar(100)',
        'IPAddress'       => 'Varchar(45)',
        'Port'            => 'Int',
        'VisibleChannels' => 'Varchar(255)',
        'PresetCount'     => 'Int',
        'SortOrder'       => 'Int',
    ];

    private static array $defaults = [
        'Port'            => 2202,
        'VisibleChannels' => '01,02,03,04,05,06,07,08,11,12,13,14,17,18,19',
        'PresetCount'     => 10,
    ];

    private static array $has_one = [
        'Location' => Location::class,
    ];

    private static array $summary_fields = [
        'Title'          => 'Device Name',
        'Location.Title' => 'Room / Location',
        'IPAddress'      => 'Control IP Address',
        'Port'           => 'TCP Port',
    ];

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    TextField::create('Title', 'Friendly Device Name')
                        ->setDescription('Display label on the touch panel (e.g., "Boardroom Shure P300")'),
                    DropdownField::create('LocationID', 'Location / Room', Location::get()->map('ID', 'Title'))
                        ->setEmptyString('-- Select a Location --'),
                    TextField::create('IPAddress', 'Shure Control IP Address')
                        ->setDescription('Use the Shure Control IP address (not the Dante Audio Network IP)'),
                    TextField::create('Port', 'TCP Control Port')
                        ->setAttribute('type', 'number')
                        ->setAttribute('inputmode', 'numeric')
                        ->setAttribute('step', '1')
                        ->setAttribute('min', '1')
                        ->setAttribute('max', '65535')
                        ->setDescription('Default Shure P300 third-party control port is 2202'),
                    TextField::create('VisibleChannels', 'Visible P300 Channels (comma-separated 2-digit IDs)')
                        ->setDescription('01-08: Dante Mic In | 09-10: Dante In | 11-12: Analog In | 13: USB In | 14: Mobile In | 15-16: Dante Out | 17-18: Analog Out | 19: USB Out | 20: Mobile Out | 23-28: Dante Out 3-8'),
                    NumericField::create('PresetCount', 'Number of Presets to Display (1-10)')
                        ->setDescription('P300 stores up to 10 hardware presets (01-10)'),
                    NumericField::create('SortOrder', 'Sort Order')
                )
            )
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', 'Device Name is required.');
        }

        if (!filter_var($this->IPAddress, FILTER_VALIDATE_IP)) {
            $result->addFieldError('IPAddress', 'Please enter a valid IPv4 or IPv6 Control IP address.');
        }

        return $result;
    }

    /**
     * Parse configured 2-digit channel numbers (excluding 00 and 21).
     *
     * @return string[]
     */
    public function getConfiguredChannelList(): array
    {
        $raw = trim((string) ($this->VisibleChannels ?: '01,02,03,04,05,06,07,08,11,12,13,14,17,18,19'));
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $valid = [];

        foreach ($parts as $part) {
            $num = (int) $part;
            if (($num >= 1 && $num <= 20) || ($num >= 23 && $num <= 28)) {
                $code = str_pad((string) $num, 2, '0', STR_PAD_LEFT);
                if (!in_array($code, $valid, true)) {
                    $valid[] = $code;
                }
            }
        }

        return $valid ?: ['01', '02', '03', '04', '05', '06', '07', '08'];
    }
}
