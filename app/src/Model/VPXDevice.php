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
        'Title' => 'Varchar(100)',         // Friendly UI name (e.g., "Stage Left Camera" or "Lobby TV 1")
        'Mode' => "Enum('Encoder,Decoder', 'Decoder')",
        'IPAddress' => 'Varchar(45)',      // Supports IPv4 or IPv6
        'UDPPort' => 'Int',                // Default Aurora VPX UDP control port: 6970
        'Hostname' => 'Varchar(100)',      // Unique VPX Hostname used in IPBaseT join commands
        'HostID' => 'Varchar(50)',         // Optional Aurora Host ID / Virtual Channel ID
        'MACAddress' => 'Varchar(17)',     // Helpful for hardware identification & DHCP reservations
        'MulticastIP' => 'Varchar(45)',    // Optional multicast stream IP
        'ChannelNumber' => 'Int',          // Optional virtual channel number (0-based)
        'SortOrder' => 'Int',
    ];

    private static array $defaults = [
        'Mode' => self::MODE_DECODER,
        'UDPPort' => 6970,
    ];

    private static array $has_one = [
        'Location' => Location::class,
        // Self-referential subscriptions (used when this device is acting as a Decoder)
        'SubscribedVideoEncoder' => self::class,
        'SubscribedAudioEncoder' => self::class,
        'SubscribedUSBEncoder' => self::class,
    ];

    private static array $has_many = [
        // Reverse lookup: if this device is an Encoder, see which Decoders are tuned to its video
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



    public function getCMSFields(): FieldList
    {
        // Filter dropdown options so Decoders only subscribe to Encoders
        $encoders = self::get()
            ->filter('Mode', self::MODE_ENCODER)
            ->exclude('ID', (int) $this->ID)
            ->map('ID', 'Title');

        $fields = FieldList::create(
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
                    'RoutingAndNetwork',
                    'Routing & Network Details',
                    DropdownField::create('SubscribedVideoEncoderID', 'Subscribed Video Source (Encoder)', $encoders)
                        ->setEmptyString('-- None / Unsubscribed --'),
                    DropdownField::create('SubscribedAudioEncoderID', 'Subscribed Audio Source (Encoder)', $encoders)
                        ->setEmptyString('-- Follow Video / None --'),
                    DropdownField::create('SubscribedUSBEncoderID', 'Subscribed USB / KVM Source (Encoder)', $encoders)
                        ->setEmptyString('-- None --'),
                    TextField::create('HostID', 'Aurora Host ID')
                        ->setDescription('Optional Host ID if routing by ID instead of Hostname'),
                    NumericField::create('ChannelNumber', 'Virtual Channel Number'),
                    TextField::create('MulticastIP', 'Multicast Stream IP'),
                    TextField::create('MACAddress', 'MAC Address')
                        ->setDescription('Format: AA:BB:CC:DD:EE:FF')
                )
            )
        );

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (empty(trim((string) $this->Title))) {
            $result->addFieldError('Title', 'Device Name is required.');
        }

        if (!filter_var($this->IPAddress, FILTER_VALIDATE_IP)) {
            $result->addFieldError('IPAddress', 'Please enter a valid IPv4 or IPv6 address.');
        }

        if (empty(trim((string) $this->Hostname))) {
            $result->addFieldError('Hostname', 'VPX Hostname is required for UDP join commands.');
        }

        // Ensure IPAddress is unique across devices
        $duplicateIP = self::get()
            ->filter('IPAddress', $this->IPAddress)
            ->exclude('ID', (int) $this->ID)
            ->first();

        if ($duplicateIP) {
            $result->addFieldError('IPAddress', "IP Address {$this->IPAddress} is already assigned to {$duplicateIP->Title}.");
        }

        return $result;
    }

    /**
     * Helper method for your upcoming UDP controller to format a join command.
     */
    public function buildJoinCommand(VPXDevice $encoder, string $streamType = 'all'): string
    {
        // Standard Aurora IPBaseT UDP syntax: join <encoder_hostname> <stream_type>\r
        return sprintf("join %s %s\r", trim($encoder->Hostname), $streamType);
    }

    public function getUDPPortDisplay(): string
    {
        return (string) $this->UDPPort;
    }

}
