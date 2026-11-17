<?php

namespace App\IBT\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

class IBTRoutePresetAction extends DataObject
{
    private static string $table_name = 'IBTRoutePresetAction';
    private static string $singular_name = 'IBT Preset Action';
    private static string $plural_name = 'IBT Preset Actions';
    private static string $default_sort = 'SortOrder ASC, ID ASC';

    private static array $db = [
        'StreamType' => "Enum('all,video,audio,usb,ir', 'all')",
        'SortOrder'  => 'Int',
    ];

    private static array $has_one = [
        'Preset'  => IBTRoutePreset::class,
        'Decoder' => IBTDevice::class,
        'Encoder' => IBTDevice::class,
    ];

    private static array $summary_fields = [
        'Decoder.Title'          => 'Target Receiver (Display)',
        'Decoder.Location.Title' => 'Receiver Room',
        'Encoder.Title'          => 'Source Transmitter',
        'StreamType'             => 'Stream Type',
    ];

    public function getTitle(): string
    {
        $decoder = $this->Decoder()->exists() ? $this->Decoder()->Title : 'Unknown Receiver';
        $encoder = $this->Encoder()->exists() ? $this->Encoder()->Title : 'Disconnect';
        return sprintf('%s -> %s (%s)', $decoder, $encoder, strtoupper((string) $this->StreamType));
    }

    public function getCMSFields(): FieldList
    {
        $decoders = IBTDevice::get()->filter('Mode', IBTDevice::MODE_DECODER)->map('ID', 'Title');
        $encoders = IBTDevice::get()->filter('Mode', IBTDevice::MODE_ENCODER)->map('ID', 'Title');

        return FieldList::create(
            DropdownField::create('DecoderID', 'Target Receiver (RX Display)', $decoders)->setEmptyString('-- Select a Receiver --'),
            DropdownField::create('EncoderID', 'Source Transmitter (TX)', $encoders)->setEmptyString('-- Unsubscribe / None --'),
            DropdownField::create('StreamType', 'Stream Type to Route', [
                'all'   => 'All Streams (Video, Audio, USB, IR)',
                'video' => 'Video Only',
                'audio' => 'Audio Only (Breakaway)',
                'usb'   => 'USB / KVM Only',
                'ir'    => 'IR Control Only',
            ])
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->DecoderID) $result->addFieldError('DecoderID', 'Please select a target Receiver.');
        if ($this->DecoderID && $this->DecoderID === $this->EncoderID) $result->addFieldError('EncoderID', 'A Receiver cannot subscribe to itself.');
        return $result;
    }
}
