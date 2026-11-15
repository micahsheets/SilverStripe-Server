<?php

namespace App\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * @property string $Title
 * @property string $Mode
 * @property string $IPAddress
 * @property int $UDPPort
 * @property string $Hostname
 * @property string|null $HostID
 * @property string|null $MACAddress
 * @property string|null $MulticastIP
 * @property int|null $ChannelNumber
 * @property string $PowerOnCommand
 * @property string $PowerOffCommand
 * @property int $SortOrder
 *
 * @property int $LocationID
 * @property int $SubscribedVideoEncoderID
 * @property int $SubscribedAudioEncoderID
 * @property int $SubscribedUSBEncoderID
 *
 * @method Location Location()
 * @method VPXDevice SubscribedVideoEncoder()
 * @method VPXDevice SubscribedAudioEncoder()
 * @method VPXDevice SubscribedUSBEncoder()
 * @method HasManyList<VPXDevice> VideoSubscribers()
 */
class VPXDevice extends DataObject
{
    public const MODE_ENCODER = 'Encoder';
    public const MODE_DECODER = 'Decoder';

    private static string $table_name = 'VPXDevice';

    private static string $singular_name = 'VPX Device';

    private static string $plural_name = 'VPX Devices';

    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Mode' => "Enum('Encoder,Decoder', 'Decoder')",
        'IPAddress' => 'Varchar(45)',
        'UDPPort' => 'Int',
        'Hostname' => 'Varchar(100)',
        'HostID' => 'Varchar(50)',
        'MACAddress' => 'Varchar(17)',
        'MulticastIP' => 'Varchar(45)',
        'ChannelNumber' => 'Int',
        'PowerOnCommand' => 'Varchar(255)',
        'PowerOffCommand' => 'Varchar(255)',
        'SortOrder' => 'Int',
    ];

    private static array $defaults = [
        'Mode' => self::MODE_DECODER,
        'UDPPort' => 6970,
        'PowerOnCommand' => 'cec power on',
        'PowerOffCommand' => 'cec power off',
    ];

    private static array $has_one = [
        'Location' => Location::class,
        'SubscribedVideoEncoder' => self::class,
        'SubscribedAudioEncoder' => self::class,
        'SubscribedUSBEncoder' => self::class,
    ];

    private static array $has_many = [
        'VideoSubscribers' => self::class . '.SubscribedVideoEncoder',
    ];

    private static array $indexes = [
        'IPAddress' => true,
        'Hostname' => true,
        'Mode' => true,
    ];

    private static array $summary_fields = [
        'Title' => 'Device Name',
        'Mode' => 'Mode',
        'Location.Title' => 'Location',
        'IPAddress' => 'IP Address',
        'UDPPortDisplay' => 'UDP Port',
        'Hostname' => 'VPX Hostname',
        'SubscribedVideoEncoder.Title' => 'Active Video Source',
    ];

    public function getUDPPortDisplay(): string
    {
        return (string) $this->UDPPort;
    }

    public function getCMSFields(): FieldList
    {
        $encoders = self::get()
            ->filter('Mode', self::MODE_ENCODER)
            ->exclude('ID', (int) $this->ID)
            ->map('ID', 'Title');

        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    TextField::create('Title', 'Friendly Device Name')
                        ->setDescription('Display label for UI buttons (e.g., "Apple TV 1" or "Main Projector")'),
                    DropdownField::create('Mode', 'Transceiver Mode', [
                        self::MODE_ENCODER => 'Encoder (Transmitter / Source)',
                        self::MODE_DECODER => 'Decoder (Receiver / Display)',
                    ]),
                    DropdownField::create('LocationID', 'Location / Room', Location::get()->map('ID', 'Title'))
                        ->setEmptyString('-- Select a Location --'),
                    TextField::create('IPAddress', 'IP Address')
                        ->setDescription('Static IP address of the VPX unit (e.g., 192.168.1.50)'),
                    NumericField::create('UDPPort', 'UDP Control Port')
                        ->setDescription('Default Aurora VPX API port is 6970'),
                    TextField::create('Hostname', 'VPX Hostname')
                        ->setDescription('Must match the exact Host Name configured on the VPX web interface')
                ),
                Tab::create(
                    'DisplayControl',
                    'Display Power (CEC / RS-232)',
                    TextField::create('PowerOnCommand', 'Power ON Command')
                        ->setDescription('Default: "cec power on" | RS-232 example: serial 1,9600,8,N,1 \\x02PON\\x03'),
                    TextField::create('PowerOffCommand', 'Power OFF Command')
                        ->setDescription('Default: "cec power off" | RS-232 example: serial 1,9600,8,N,1 \\x02POF\\x03')
                ),
                Tab::create(
                    'RoutingAndNetwork',
                    'Routing & Network Details',
                    DropdownField::create('SubscribedVideoEncoderID', 'Subscribed Video Source (Encoder)', $encoders)
                        ->setEmptyString('-- None / Unsubscribed --'),
                    DropdownField::create('SubscribedAudioEncoderID', 'Subscribed Audio Source (Encoder)', $encoders)
                        ->setEmptyString('-- Follow Video / None --'),
                    DropdownField::create('SubscribedUSBEncoderID', 'Subscribed USB / KVM Source (Encoder)', $encoders)
                        ->setEmptyString('-- None --'),
                    TextField::create('HostID', 'Aurora Host ID'),
                    NumericField::create('ChannelNumber', 'Virtual Channel Number'),
                    TextField::create('MulticastIP', 'Multicast Stream IP'),
                    TextField::create('MACAddress', 'MAC Address')
                )
            )
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (empty(trim((string) $this->Title))) {$result->addFieldError('Title', 'Device Name is required.');
        }

        if (!filter_var($this->IPAddress, FILTER_VALIDATE_IP)) {$result->addFieldError('IPAddress', 'Please enter a valid IPv4 or IPv6 address.');
        }

        if (empty(trim((string) $this->Hostname))) {$result->addFieldError('Hostname', 'VPX Hostname is required for UDP join commands.');
        }

        $duplicateIP = self::get()
            ->filter('IPAddress', $this->IPAddress)
            ->exclude('ID', (int) $this->ID)
            ->first();

        if ($duplicateIP) {$result->addFieldError('IPAddress', "IP Address {$this->IPAddress} is already assigned to {$duplicateIP->Title}.");
        }

        return $result;
    }

    /**
     * Build the VPX TCP 6970 join command(s) to subscribe this decoder to an encoder.
     *
     * @return string[]
     */
    public function buildJoinCommand(VPXDevice $encoder, string$streamType = 'all'): array
    {
        $host = trim((string) ($encoder->Hostname ?: $encoder->IPAddress));
        $type = strtolower(trim($streamType));

        if ($type === 'all') {
            return [
                'set local_display_source STREAM',
                "join video {$host}",
                "join audio {$host}",
            ];
        }

        if ($type === 'video') {
            return [
                'set local_display_source STREAM',
                "join video {$host}",
            ];
        }

        return ["join {$type} {$host}"];
    }

    /**
     * Build the VPX TCP 6970 leave command(s) to unsubscribe this decoder from a stream.
     *
     * @return string[]
     */
    public function buildLeaveCommand(string $streamType = 'all'): array
    {
        $type = strtolower(trim($streamType));

        if ($type === 'all') {
            return [
                'leave video',
                'leave audio',
            ];
        }

        return ["leave {$type}"];
    }
}
