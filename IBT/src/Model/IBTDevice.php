<?php

namespace App\IBT\Model;

use App\Model\Location;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class IBTDevice extends DataObject
{
    public const MODE_ENCODER = 'Encoder';
    public const MODE_DECODER = 'Decoder';

    private static string $table_name = 'IBTDevice';
    private static string $singular_name = 'IBT-1-BX Device';
    private static string $plural_name = 'IBT-1-BX Devices';
    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title'                => 'Varchar(100)',
        'Mode'                 => "Enum('Encoder,Decoder', 'Decoder')",
        'IPAddress'            => 'Varchar(45)',
        'ControlPort'          => 'Int',
        'Hostname'             => 'Varchar(100)',
        'HostID'               => 'Varchar(50)',
        'MACAddress'           => 'Varchar(17)',
        'MulticastIP'          => 'Varchar(45)',
        'ChannelNumber'        => 'Int',
        'UseChannelMode'       => 'Boolean(0)',
        'PowerOnCommand'       => 'Varchar(255)',
        'PowerOffCommand'      => 'Varchar(255)',
        'LocalAudioOffCommand' => 'Varchar(255)',
        'SortOrder'            => 'Int',
    ];

    private static array $defaults = [
        'Mode'                 => self::MODE_DECODER,
        'ControlPort'          => 6970,
        'PowerOnCommand'       => 'cec power on',
        'PowerOffCommand'      => 'cec power off',
        'LocalAudioOffCommand' => 'set local_audio_source STREAM',
    ];

    private static array $has_one = [
        'Location'               => Location::class,
        'SubscribedVideoEncoder' => self::class,
        'SubscribedAudioEncoder' => self::class,
        'SubscribedUSBEncoder'   => self::class,
    ];

    private static array $has_many = [
        'VideoSubscribers' => self::class . '.SubscribedVideoEncoder',
        'SerialCommands'   => IBTSerialCommand::class . '.Device',
    ];

    private static array $indexes = [
        'IPAddress' => true,
        'Hostname'  => true,
        'Mode'      => true,
    ];

    private static array $summary_fields = [
        'Title'                        => 'Device Name',
        'Mode'                         => 'Mode',
        'Location.Title'               => 'Location',
        'IPAddress'                    => 'IP Address',
        'ControlPortDisplay'           => 'API Port',
        'Hostname'                     => 'IBT Hostname',
        'SubscribedVideoEncoder.Title' => 'Active Video Source',
    ];

    public function getControlPortDisplay(): string
    {
        return (string) ($this->ControlPort ?: 6970);
    }

    public function getCMSFields(): FieldList
    {
        $encoders = self::get()->filter('Mode', self::MODE_ENCODER)->exclude('ID', (int) $this->ID)->map('ID', 'Title');
        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    TextField::create('Title', 'Friendly Device Name'),
                    DropdownField::create('Mode', 'IBT-1-BX Transceiver Mode', [
                        self::MODE_ENCODER => 'Transmitter / Encoder (TX Source)',
                        self::MODE_DECODER => 'Receiver / Decoder (RX Display)',
                    ]),
                    DropdownField::create('LocationID', 'Location / Room', Location::get()->map('ID', 'Title'))->setEmptyString('-- Select a Location --'),
                    TextField::create('IPAddress', 'IP Address'),
                    NumericField::create('ControlPort', 'TCP/UDP Control Port (default 6970)')->setHTML5(true),
                    TextField::create('Hostname', 'IBT-1-BX Hostname'),
                    NumericField::create('SortOrder', 'Sort Order')->setHTML5(true)
                ),
                Tab::create(
                    'DisplayControl',
                    'Display Power (CEC / RS-232)',
                    TextField::create('PowerOnCommand', 'Power ON Command'),
                    TextField::create('PowerOffCommand', 'Power OFF Command'),
                    TextField::create('LocalAudioOffCommand', 'Disconnect Audio Reset Command')
                ),
                Tab::create(
                    'RS232Commands',
                    'RS-232 Buttons',
                    GridField::create('SerialCommands', 'Custom RS-232 Command Buttons', $this->SerialCommands(), GridFieldConfig_RecordEditor::create())
                ),
                Tab::create(
                    'RoutingAndNetwork',
                    'Routing & Network Details',
                    DropdownField::create('SubscribedVideoEncoderID', 'Subscribed Video Source (TX)', $encoders)->setEmptyString('-- None / Unsubscribed --'),
                    DropdownField::create('SubscribedAudioEncoderID', 'Subscribed Audio Source (TX)', $encoders)->setEmptyString('-- Follow Video / None --'),
                    DropdownField::create('SubscribedUSBEncoderID', 'Subscribed USB / KVM Source (TX)', $encoders)->setEmptyString('-- None --'),
                    NumericField::create('ChannelNumber', 'Stream Channel ID (0-255)')->setHTML5(true),
                    CheckboxField::create('UseChannelMode', 'Route via Channel ID (set channel N) instead of Hostname join'),
                    TextField::create('HostID', 'Aurora Host ID'),
                    TextField::create('MulticastIP', 'Multicast Stream IP'),
                    TextField::create('MACAddress', 'MAC Address')
                )
            )
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') $result->addFieldError('Title', 'Device Name is required.');
        if (!filter_var($this->IPAddress, FILTER_VALIDATE_IP)) $result->addFieldError('IPAddress', 'Please enter a valid IPv4 or IPv6 address.');
        if (trim((string) $this->Hostname) === '') $result->addFieldError('Hostname', 'IBT-1-BX Hostname is required.');
        $dup = self::get()->filter('IPAddress', $this->IPAddress)->exclude('ID', (int) $this->ID)->first();
        if ($dup) $result->addFieldError('IPAddress', "IP Address {$this->IPAddress} is already assigned to {$dup->Title}.");
        return $result;
    }

    public function buildJoinCommand(IBTDevice $encoder, string $streamType = 'all'): array
    {
        if ($this->UseChannelMode || $encoder->UseChannelMode) {
            $ch = max(0, min(255, (int) $encoder->ChannelNumber));
            return ['set local_display_source STREAM', "set channel {$ch}"];
        }
        $host = trim((string) ($encoder->Hostname ?: $encoder->IPAddress));
        $type = strtolower(trim($streamType));
        if ($type === 'all') return ['set local_display_source STREAM', "join video {$host}", "join audio {$host}"];
        if ($type === 'video') return ['set local_display_source STREAM', "join video {$host}"];
        return ["join {$type} {$host}"];
    }

    public function buildLeaveCommand(string $streamType = 'all'): array
    {
        $type = strtolower(trim($streamType));
        if ($type === 'all') {
            $streamAudioCmd = trim((string) ($this->LocalAudioOffCommand ?: 'set local_audio_source STREAM'));
            return ['leave video', 'leave audio', $streamAudioCmd];
        }
        return ["leave {$type}"];
    }
}
