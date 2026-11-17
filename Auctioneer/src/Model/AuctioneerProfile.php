<?php

namespace App\Auctioneer\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class AuctioneerProfile extends DataObject
{
    private static string $table_name = 'AuctioneerProfile';
    private static string $singular_name = 'Auctioneer';
    private static string $plural_name = 'Auctioneers';
    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title'             => 'Varchar(100)',
        'PinCode'           => 'Varchar(12)',
        'Active'            => 'Boolean(1)',
        'SortOrder'         => 'Int',
        'SensitivityStep'   => 'Int',
        'InputGainDb'       => 'Float',
        'PeqEnabled'        => 'Boolean(1)',
        'PeqBandsJson'      => 'Text',
        'CompEnabled'       => 'Boolean(1)',
        'CompGainDb'        => 'Float',
        'CompThresholdDb'   => 'Float',
        'CompRatio'         => 'Float',
        'CompAttackMs'      => 'Int',
        'CompReleaseMs'     => 'Int',
    ];

    private static array $defaults = [
        'Active'          => 1,
        'SensitivityStep' => 10,
        'InputGainDb'     => 0.0,
        'PeqEnabled'      => 1,
        'CompEnabled'     => 1,
        'CompGainDb'      => 0.0,
        'CompThresholdDb' => -24.0,
        'CompRatio'       => 3.0,
        'CompAttackMs'    => 10,
        'CompReleaseMs'   => 500,
    ];

    private static array $indexes = [
        'Active' => true,
    ];

    private static array $summary_fields = [
        'Title'           => 'Auctioneer Name',
        'PinCode'         => 'PIN',
        'SensitivityDb'   => 'Mic Sens',
        'InputGainDb'     => 'Gain (dB)',
        'CompSummary'     => 'Compressor',
        'Active.Nice'     => 'Active',
    ];

    public static function defaultPeqBands(): array
    {
        $freqs = [125, 500, 1000, 2000, 4000, 8000, 16000, 16000];
        $bands = [];
        foreach ($freqs as $f) {
            $bands[] = [
                'enabled' => 1,
                'type'    => 6,
                'freq'    => $f,
                'gain'    => 0.0,
                'q'       => 0.71,
            ];
        }
        return $bands;
    }

    public function getSensitivityDb(): string
    {
        return ((int) $this->SensitivityStep * 3) . ' dB';
    }

    public function getCompSummary(): string
    {
        if (!$this->CompEnabled) {
            return 'Off';
        }
        return sprintf('%.0f dB (%.1f:1)', $this->CompThresholdDb, $this->CompRatio ?: 1.0);
    }

    public function getParsedPeqBands(): array
    {
        $decoded = json_decode((string) $this->PeqBandsJson, true);
        if (!is_array($decoded) || count($decoded) < 8) {
            return self::defaultPeqBands();
        }
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $b = $decoded[$i] ?? [];
            $out[] = [
                'enabled' => isset($b['enabled']) ? (int) (bool) $b['enabled'] : 1,
                'type'    => (int) ($b['type'] ?? 6),
                'freq'    => max(20, min(20000, (int) ($b['freq'] ?? 1000))),
                'gain'    => max(-24.0, min(18.0, (float) ($b['gain'] ?? 0.0))),
                'q'       => max(0.02, min(50.0, (float) ($b['q'] ?? 0.71))),
            ];
        }
        return $out;
    }

    public function toDspSettingsArray(): array
    {
        return [
            'sensitivity' => max(0, min(16, (int) $this->SensitivityStep)),
            'gain'        => round(max(-72.0, min(12.0, (float) $this->InputGainDb)), 2),
            'muted'       => false,
            'peq'         => [
                'enabled' => $this->PeqEnabled ? 1 : 0,
                'band'    => $this->getParsedPeqBands(),
            ],
            'comp'        => [
                'enabled'   => $this->CompEnabled ? 1 : 0,
                'gain'      => round(max(-72.0, min(6.0, (float) $this->CompGainDb)), 2),
                'threshold' => round(max(-48.0, min(0.0, (float) $this->CompThresholdDb)), 2),
                'ratio'     => round(max(1.0, min(20.0, (float) ($this->CompRatio ?: 1.0))), 2),
                'attack'    => max(1, min(500, (int) ($this->CompAttackMs ?: 10))),
                'release'   => max(1, min(10000, (int) ($this->CompReleaseMs ?: 500))),
            ],
        ];
    }

    public function applyDspSettingsArray(array $s): void
    {
        if (isset($s['sensitivity'])) {
            $this->SensitivityStep = max(0, min(16, (int) $s['sensitivity']));
        }
        if (isset($s['gain'])) {
            $this->InputGainDb = max(-72.0, min(12.0, (float) $s['gain']));
        }
        if (isset($s['peq']) && is_array($s['peq'])) {
            $this->PeqEnabled = !empty($s['peq']['enabled']) ? 1 : 0;
            if (isset($s['peq']['band']) && is_array($s['peq']['band'])) {
                $this->PeqBandsJson = json_encode(array_values($s['peq']['band']));
            }
        }
        if (isset($s['comp']) && is_array($s['comp'])) {
            $c = $s['comp'];
            $this->CompEnabled     = !empty($c['enabled']) ? 1 : 0;
            $this->CompGainDb      = max(-72.0, min(6.0, (float) ($c['gain'] ?? $this->CompGainDb)));
            $this->CompThresholdDb = max(-48.0, min(0.0, (float) ($c['threshold'] ?? $this->CompThresholdDb)));
            $this->CompRatio       = max(1.0, min(20.0, (float) ($c['ratio'] ?? $this->CompRatio ?: 1.0)));
            $this->CompAttackMs    = max(1, min(500, (int) ($c['attack'] ?? $this->CompAttackMs ?: 10)));
            $this->CompReleaseMs   = max(1, min(10000, (int) ($c['release'] ?? $this->CompReleaseMs ?: 500)));
        }
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (trim((string) $this->PeqBandsJson) === '') {
            $this->PeqBandsJson = json_encode(self::defaultPeqBands());
        }
    }

    public function getCMSFields(): FieldList
    {
        $sensOptions = [];
        for ($i = 0; $i <= 16; $i++) {
            $sensOptions[$i] = sprintf('Step %d (+%d dB)', $i, $i * 3);
        }

        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    TextField::create('Title', 'Auctioneer Name'),
                    TextField::create('PinCode', 'PIN (4-8 digits, can be shared across users)')
                        ->setAttribute('inputmode', 'numeric')
                        ->setAttribute('maxlength', '8'),
                    CheckboxField::create('Active', 'Active (Show on Touch Panels)'),
                    NumericField::create('SortOrder', 'Sort Order')->setHTML5(true)
                ),
                Tab::create(
                    'GainAndSensitivity',
                    'Mic Gain & Sensitivity',
                    DropdownField::create('SensitivityStep', 'Mic Preamp Sensitivity (0 - 48 dB)', $sensOptions),
                    NumericField::create('InputGainDb', 'Input Channel Gain / Volume (-72.0 to +12.0 dB)')->setScale(2)->setHTML5(true)
                ),
                Tab::create(
                    'EQAndCompressor',
                    '8-Band PEQ & Compressor',
                    CheckboxField::create('PeqEnabled', 'Enable 8-Band Parametric EQ'),
                    TextareaField::create('PeqBandsJson', '8-Band PEQ JSON (Bands 1-8: enabled, type, freq, gain, q)')->setRows(8),
                    CheckboxField::create('CompEnabled', 'Enable Input Compressor'),
                    NumericField::create('CompThresholdDb', 'Threshold (-48.0 to 0.0 dB)')->setScale(1)->setHTML5(true),
                    NumericField::create('CompRatio', 'Ratio (1.00 to 20.00)')->setScale(2)->setHTML5(true),
                    NumericField::create('CompGainDb', 'Makeup Gain (-72.0 to +6.0 dB)')->setScale(1)->setHTML5(true),
                    NumericField::create('CompAttackMs', 'Attack (1 - 500 ms)')->setHTML5(true),
                    NumericField::create('CompReleaseMs', 'Release (1 - 10000 ms)')->setHTML5(true)
                )
            )
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $name = trim((string) $this->Title);
        $pin = trim((string) $this->PinCode);

        if ($name === '') {
            $result->addFieldError('Title', 'Auctioneer Name is required.');
        }
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            $result->addFieldError('PinCode', 'PIN must be 4 to 8 numeric digits.');
        }
        return $result;
    }
}
