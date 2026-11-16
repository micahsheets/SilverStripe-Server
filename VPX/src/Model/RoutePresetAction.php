<?php

namespace App\VPX\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * @property string $StreamType
 * @property int $SortOrder
 *
 * @property int $PresetID
 * @property int $DecoderID
 * @property int $EncoderID
 *
 * @method RoutePreset Preset()
 * @method VPXDevice Decoder()
 * @method VPXDevice Encoder()
 */
class RoutePresetAction extends DataObject
{
    private static string $table_name = 'RoutePresetAction';

    private static string $singular_name = 'Preset Routing Action';

    private static string $plural_name = 'Preset Routing Actions';

    private static string $default_sort = 'SortOrder ASC, ID ASC';

    private static array $db = [
        'StreamType' => "Enum('all,video,audio,usb,ir', 'all')",
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        'Preset' => RoutePreset::class,
        'Decoder' => VPXDevice::class,
        'Encoder' => VPXDevice::class,
    ];

    private static array $summary_fields = [
        'Decoder.Title' => 'Target Decoder (Display)',
        'Decoder.Location.Title' => 'Decoder Room',
        'Encoder.Title' => 'Source Encoder',
        'StreamType' => 'Stream Type',
    ];

    public function getTitle(): string
    {
        $decoder = $this->Decoder()->exists() ? $this->Decoder()->Title : 'Unknown Decoder';
        $encoder = $this->Encoder()->exists() ? $this->Encoder()->Title : 'Unsubscribe';

        return sprintf('%s -> %s (%s)', $decoder, $encoder, strtoupper((string) $this->StreamType));
    }

    public function getCMSFields(): FieldList
    {
        $decoders = VPXDevice::get()
            ->filter('Mode', VPXDevice::MODE_DECODER)
            ->map('ID', 'Title');

        $encoders = VPXDevice::get()
            ->filter('Mode', VPXDevice::MODE_ENCODER)
            ->map('ID', 'Title');

        return FieldList::create(
            DropdownField::create('DecoderID', 'Target Decoder (Receiver / Display)', $decoders)
                ->setEmptyString('-- Select a Decoder --'),
            DropdownField::create('EncoderID', 'Source Encoder (Transmitter)', $encoders)
                ->setEmptyString('-- Unsubscribe / None --'),
            DropdownField::create('StreamType', 'Stream Type to Route', [
                'all' => 'All Streams (Video, Audio, USB, IR)',
                'video' => 'Video Only',
                'audio' => 'Audio Only (Breakaway)',
                'usb' => 'USB / KVM Only',
                'ir' => 'IR Control Only',
            ])
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->DecoderID) {
            $result->addFieldError('DecoderID', 'Please select a target Decoder.');
        }

        if ($this->DecoderID && $this->DecoderID === $this->EncoderID) {
            $result->addFieldError('EncoderID', 'A Decoder cannot subscribe to itself.');
        }

        return $result;
    }
}
