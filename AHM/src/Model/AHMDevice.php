<?php

namespace App\AHM\Model;

use App\Model\Location;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

class AHMDevice extends DataObject
{
    private static string $table_name = 'AHMDevice';
    private static string $singular_name = 'Allen & Heath AHM DSP';
    private static string $plural_name = 'Allen & Heath AHM DSPs';
    private static string $default_sort = 'SortOrder ASC, Title ASC';

    private static array $db = [
        'Title'             => 'Varchar(100)',
        'IPAddress'         => 'Varchar(45)',
        'Port'              => 'Int',
        'DeviceModel'       => 'Varchar(20)',
        'MidiChannel'       => 'Int',
        'ZonesConfig'       => 'Text',
        'InputsConfig'      => 'Text',
        'GroupsConfig'      => 'Text',
        'SendsConfig'       => 'Text',
        'PresetsConfig'     => 'Text',
        'DiscoveredCatalog' => 'Text',
        'Active'            => 'Boolean',
        'SortOrder'         => 'Int',
    ];

    private static array $defaults = [
        'Port'          => 51325,
        'DeviceModel'   => 'AHM-16',
        'MidiChannel'   => 1,
        'ZonesConfig'   => "1:Zone 1 (Main)\n2:Zone 2 (Lobby)",
        'InputsConfig'  => "1:Pulpit Mic\n2:Handheld 1",
        'GroupsConfig'  => "1:All Microphones",
        'SendsConfig'   => "1>2:Pulpit Mic -> Lobby",
        'PresetsConfig' => "1:Sunday Service\n2:Speech Only\n3:All Muted",
        'Active'        => 1,
    ];

    private static array $has_one = [
        'Location' => Location::class,
    ];

    private static array $summary_fields = [
        'Title'          => 'Processor Name',
        'DeviceModel'    => 'Model',
        'Location.Title' => 'Room / Location',
        'IPAddress'      => 'IP Address',
        'Port'           => 'TCP Port',
        'Active.Nice'    => 'Active',
    ];

    public function getCMSFields(): FieldList
    {
        $max = $this->getMaxChannels();
        $catalog = json_decode((string) $this->DiscoveredCatalog, true) ?: [];

        $selectedZones = $this->mapByKey($this->parseNumberedList((string) $this->ZonesConfig, $max), 'num');
        $selectedInputs = $this->mapByKey($this->parseNumberedList((string) $this->InputsConfig, $max), 'num');
        $selectedGroups = $this->mapByKey($this->parseNumberedList((string) $this->GroupsConfig, 32), 'num');
        $selectedSends = $this->mapByKey($this->parseSendsList((string) $this->SendsConfig, $max), 'key');
        $selectedPresets = $this->mapByKey($this->parseNumberedList((string) $this->PresetsConfig, 500), 'num');

        $zonesCatalog = $catalog['zones'] ?? array_map(fn($n) => [
            'num' => $n, 'label' => $selectedZones[$n]['label'] ?? "Zone {$n}", 'db' => null, 'muted' => false,
        ], range(1, $max));

        $inputsCatalog = $catalog['inputs'] ?? array_map(fn($n) => [
            'num' => $n, 'label' => $selectedInputs[$n]['label'] ?? "Input {$n}", 'db' => null, 'muted' => false,
        ], range(1, $max));

        $groupsCatalog = $catalog['groups'] ?? array_map(fn($n) => [
            'num' => $n, 'label' => $selectedGroups[$n]['label'] ?? "Control Group {$n}", 'db' => null, 'muted' => false,
        ], range(1, 32));

        $presetsCatalog = $catalog['presets'] ?? array_map(fn($n) => [
            'num' => $n, 'label' => $selectedPresets[$n]['label'] ?? "Preset {$n}", 'db' => null, 'muted' => false,
        ], range(1, 500));

        $sendsCatalog = $catalog['sends'] ?? [];
        foreach ($selectedSends as $k => $sItem) {
            if (!array_filter($sendsCatalog, fn($x) => ($x['key'] ?? '') === $k)) {
                $sendsCatalog[] = [
                    'key' => $k, 'input' => $sItem['input'], 'zone' => $sItem['zone'],
                    'label' => $sItem['label'], 'db' => null, 'assigned' => true,
                ];
            }
        }

        $pickerPayload = htmlspecialchars(json_encode([
            'deviceId' => (int) $this->ID, 'fetchedAt' => $catalog['fetchedAt'] ?? '',
            'zones' => $zonesCatalog, 'inputs' => $inputsCatalog,
            'groups' => $groupsCatalog, 'sends' => $sendsCatalog, 'presets' => $presetsCatalog,
            'selectedZones' => $selectedZones, 'selectedInputs' => $selectedInputs,
            'selectedGroups' => $selectedGroups, 'selectedSends' => $selectedSends,
            'selectedPresets' => $selectedPresets,
        ]), ENT_QUOTES, 'UTF-8');

        $pickerHtml = $this->buildPickerHtml($pickerPayload);

        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    TextField::create('Title', 'Friendly Processor Name'),
                    DropdownField::create('LocationID', 'Location / Room', Location::get()->map('ID', 'Title'))->setEmptyString('-- Select a Location --'),
                    DropdownField::create('DeviceModel', 'AHM Model', [
                        'AHM-16' => 'AHM-16 (16x16 Matrix)',
                        'AHM-32' => 'AHM-32 (32x32 Matrix)',
                        'AHM-64' => 'AHM-64 (64x64 Matrix)',
                    ]),
                    TextField::create('IPAddress', 'AHM Network IP Address'),
                    NumericField::create('Port', 'TCP Control Port (default 51325)')->setHTML5(true),
                    NumericField::create('MidiChannel', 'Base MIDI Channel (1-16)')->setHTML5(true),
                    CheckboxField::create('Active', 'Active on Touch Panel'),
                    NumericField::create('SortOrder', 'Sort Order')->setHTML5(true)
                ),
                Tab::create(
                    'Channels & Zones',
                    LiteralField::create('AHMChannelPicker', $pickerHtml),
                    HiddenField::create('PresetsConfig'),
                    HiddenField::create('ZonesConfig'),
                    HiddenField::create('InputsConfig'),
                    HiddenField::create('GroupsConfig'),
                    HiddenField::create('SendsConfig')
                )
            )
        );
    }

    private function buildPickerHtml(string $pickerPayload): string
    {
        return <<<HTML
<div id="ahm-cms-picker" data-config="{$pickerPayload}" style="border:1px solid #cbd5e1; border-radius:8px; padding:14px; background:#f8fafc; margin-bottom:14px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <div>
            <strong style="font-size:14px; color:#0f172a;">Live AHM Channel, Crosspoint &amp; Preset Selector</strong>
            <div id="ahm-sync-meta" style="font-size:12px; color:#475569;">Check the Zones, Inputs, Groups, Crosspoints, and Presets (1–500) to display on the touch panel, then click Save.</div>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <label style="font-size:12px; display:flex; align-items:center; gap:4px; color:#334155;">
                <input type="checkbox" id="ahm-include-all-xpoints"> Include unassigned crosspoints
            </label>
            <button type="button" id="btn-ahm-sync-catalog" class="btn btn-primary" style="padding:6px 14px; font-weight:600;">Sync All Channels &amp; Levels from AHM</button>
        </div>
    </div>
    <div id="ahm-picker-sections" style="display:grid; gap:14px;"></div>
</div>
<script src="/js/ahm-cms-picker.js"></script>
HTML;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', 'Processor Name is required.');
        }
        if (!filter_var($this->IPAddress, FILTER_VALIDATE_IP)) {
            $result->addFieldError('IPAddress', 'Please enter a valid IPv4 or IPv6 address.');
        }
        return $result;
    }

    public function getMaxChannels(): int
    {
        return match ($this->DeviceModel) {
            'AHM-64' => 64,
            'AHM-32' => 32,
            default  => 16,
        };
    }

    public function parseNumberedList(string $raw, int $max): array
    {
        $items = [];
        foreach (preg_split('/[\r\n,]+/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            [$numStr, $label] = strpos($line, ':') !== false ? explode(':', $line, 2) : [$line, ''];
            $num = (int) trim($numStr);
            if ($num >= 1 && $num <= $max) {
                $items[] = ['num' => $num, 'label' => trim($label) !== '' ? trim($label) : "Ch {$num}"];
            }
        }
        return $items;
    }

    public function parseSendsList(string $raw, int $max): array
    {
        $items = [];
        foreach (preg_split('/[\r\n,]+/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '>') === false) continue;
            [$pair, $label] = array_pad(explode(':', $line, 2), 2, '');
            [$inStr, $zoneStr] = array_pad(explode('>', $pair, 2), 2, '');
            $in = (int) trim($inStr);
            $zone = (int) trim($zoneStr);
            if ($in >= 1 && $in <= $max && $zone >= 1 && $zone <= $max) {
                $items[] = ['key' => "{$in}>{$zone}", 'input' => $in, 'zone' => $zone, 'label' => trim($label) !== '' ? trim($label) : "In {$in} -> Zone {$zone}"];
            }
        }
        return $items;
    }

    private function mapByKey(array $items, string $key): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[(string) $item[$key]] = $item;
        }
        return $out;
    }
}
