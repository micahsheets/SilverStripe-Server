<?php

namespace App\Auctioneer\Model;

use App\DTX\Model\DTXDevice;
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

class AuctionLane extends DataObject
{
    private static string $table_name = 'AuctionLane';
    private static string $singular_name = 'Auction Lane';
    private static string $plural_name = 'Auction Lanes';
    private static string $default_sort = 'LaneNumber ASC, Title ASC';

    private static array $db = [
        'LaneNumber'             => 'Int',
        'Title'                  => 'Varchar(100)',
        'DefaultSensitivityStep' => 'Int',
        'DefaultInputGainDb'     => 'Float',
        'DefaultPeqEnabled'      => 'Boolean(1)',
        'DefaultCompEnabled'     => 'Boolean(1)',
        'DefaultCompThresholdDb' => 'Float',
        'DefaultCompRatio'       => 'Float',
        'DefaultCompGainDb'      => 'Float',
        'DefaultCompAttackMs'    => 'Int',
        'DefaultCompReleaseMs'   => 'Int',
        'MuteOnLogout'           => 'Boolean(1)',
    ];

    private static array $defaults = [
        'LaneNumber'             => 1,
        'DefaultSensitivityStep' => 0,
        'DefaultInputGainDb'     => 0.0,
        'DefaultPeqEnabled'      => 1,
        'DefaultCompEnabled'     => 1,
        'DefaultCompThresholdDb' => -48.0,
        'DefaultCompRatio'       => 1.0,
        'DefaultCompGainDb'      => 0.0,
        'DefaultCompAttackMs'    => 10,
        'DefaultCompReleaseMs'   => 500,
        'MuteOnLogout'           => 1,
    ];

    private static array $has_one = [
        'DTXDevice' => DTXDevice::class,
    ];

    private static array $has_many = [
        'Stations' => AuctionPanelStation::class . '.Lane',
    ];

    private static array $summary_fields = [
        'LaneNumber'        => 'Lane #',
        'Title'             => 'Lane Name',
        'DTXDevice.Title'   => 'DTX Unit',
        'Stations.Count'    => 'Touch Panels',
        'MuteOnLogout.Nice' => 'Mute on Logout',
    ];

    public function getDefaultDspSettingsArray(): array
    {
        return [
            'sensitivity' => max(0, min(16, (int) $this->DefaultSensitivityStep)),
            'gain'        => round(max(-72.0, min(12.0, (float) $this->DefaultInputGainDb)), 2),
            'muted'       => (bool) $this->MuteOnLogout,
            'peq'         => [
                'enabled' => $this->DefaultPeqEnabled ? 1 : 0,
                'band'    => AuctioneerProfile::defaultPeqBands(),
            ],
            'comp'        => [
                'enabled'   => $this->DefaultCompEnabled ? 1 : 0,
                'gain'      => round((float) $this->DefaultCompGainDb, 2),
                'threshold' => round((float) $this->DefaultCompThresholdDb, 2),
                'ratio'     => round((float) ($this->DefaultCompRatio ?: 1.0), 2),
                'attack'    => max(1, (int) ($this->DefaultCompAttackMs ?: 10)),
                'release'   => max(1, (int) ($this->DefaultCompReleaseMs ?: 500)),
            ],
        ];
    }

    public function getCMSFields(): FieldList
    {
        $dtxOptions = DTXDevice::get()->filter('Active', 1)->map('ID', 'Title');
        $sensOptions = [];
        for ($i = 0; $i <= 16; $i++) {
            $sensOptions[$i] = sprintf('Step %d (+%d dB)', $i, $i * 3);
        }

        $fields = FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    NumericField::create('LaneNumber', 'Lane Number (1 - 16)')->setHTML5(true),
                    TextField::create('Title', 'Lane Title (e.g. Lane 01)'),
                    DropdownField::create('DTXDeviceID', 'Assigned Aurora DTX Unit', $dtxOptions)
                        ->setEmptyString('-- Select DTX Unit --')
                ),
                Tab::create(
                    'LogoutDefaults',
                    'Default / Logout Mic State',
                    CheckboxField::create('MuteOnLogout', 'Mute Mic Channel When Auctioneer Logs Out'),
                    DropdownField::create('DefaultSensitivityStep', 'Default Mic Sensitivity', $sensOptions),
                    NumericField::create('DefaultInputGainDb', 'Default Input Gain (-72.0 to +12.0 dB)')->setScale(1)->setHTML5(true),
                    CheckboxField::create('DefaultPeqEnabled', 'Default 8-Band PEQ Enabled'),
                    CheckboxField::create('DefaultCompEnabled', 'Default Compressor Enabled'),
                    NumericField::create('DefaultCompThresholdDb', 'Default Compressor Threshold (dB)')->setScale(1)->setHTML5(true),
                    NumericField::create('DefaultCompRatio', 'Default Compressor Ratio')->setScale(2)->setHTML5(true)
                )
            )
        );

        if ($this->isInDB()) {
            $fields->addFieldToTab(
                'Root.TouchPanels',
                GridField::create(
                    'Stations',
                    'Lane Touch Panels & Mic Channels (Up to 4 per DTX)',
                    $this->Stations(),
                    GridFieldConfig_RecordEditor::create()
                )
            );
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $this->Title = sprintf('Lane %02d', max(1, (int) $this->LaneNumber));
        }
        return $result;
    }
}
