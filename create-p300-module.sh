#!/usr/bin/env bash
set -euo pipefail

echo "=== 1. Creating directory structure for P300 module ==="
mkdir -p P300/_config \
         P300/src/Admin \
         P300/src/Controller \
         P300/src/Extensions \
         P300/src/Model \
         P300/src/Service \
         P300/templates/App/P300/Controller \
         P300/templates/App/P300/Includes

touch P300/_config.php

echo "=== 2. Creating P300/src/Model/P300Device.php ==="
cat <<'PHP' > P300/src/Model/P300Device.php
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
                    NumericField::create('Port', 'TCP Control Port')
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
PHP

echo "=== 3. Creating P300AuditLog & P300AuditExtension ==="
cat <<'PHP' > P300/src/Model/P300AuditLog.php
<?php

namespace App\P300\Model;

use SilverStripe\ORM\DataObject;

class P300AuditLog extends DataObject
{
    private static string $table_name = 'P300AuditLog';

    private static string $singular_name = 'P300 Audit Log';

    private static string $plural_name = 'P300 Audit Logs';

    private static array $db = [
        'Action'       => 'Varchar(50)',
        'Summary'      => 'Varchar(255)',
        'Command'      => 'Text',
        'Success'      => 'Boolean(1)',
        'ErrorMessage' => 'Text',
        'Actor'        => 'Varchar(100)',
    ];

    private static array $has_one = [
        'TargetDevice' => P300Device::class,
    ];

    private static string $default_sort = 'Created DESC';

    private static array $summary_fields = [
        'Created.Nice' => 'Timestamp',
        'Action'       => 'Action',
        'Summary'      => 'Summary',
        'Actor'        => 'Actor',
        'Success.Nice' => 'Success',
        'ErrorMessage' => 'Error',
    ];

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
PHP

cat <<'PHP' > P300/src/Extensions/P300AuditExtension.php
<?php

namespace App\P300\Extensions;

use App\P300\Model\P300AuditLog;
use App\P300\Model\P300Device;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;

class P300AuditExtension extends Extension
{
    public function onPanelAudit(
        string $type,
        string $summary,
        ?string $command = null,
        bool $success = true,
        ?string $error = null,
        ?P300Device $target = null
    ): void {
        $req = Controller::curr()?->getRequest()
            ?? (Injector::inst()->has(HTTPRequest::class) ? Injector::inst()->get(HTTPRequest::class) : null);

        P300AuditLog::create([
            'Action'         => $type,
            'Summary'        => $summary,
            'Command'        => $command,
            'Success'        => $success,
            'ErrorMessage'   => $error,
            'Actor'          => $req ? PanelSecurityGuard::getActorLabel($req) : 'System',
            'TargetDeviceID' => $target?->ID ?? 0,
        ])->write();
    }
}
PHP

echo "=== 4. Creating P300/src/Service/P300Service.php (TCP 2202 ASCII Client) ==="
cat <<'PHP' > P300/src/Service/P300Service.php
<?php

namespace App\P300\Service;

use App\P300\Model\P300Device;
use RuntimeException;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;

class P300Service
{
    use Extensible;
    use Injectable;

    public const DEFAULT_CHANNEL_LABELS = [
        '01' => 'Dante Mic 1',
        '02' => 'Dante Mic 2',
        '03' => 'Dante Mic 3',
        '04' => 'Dante Mic 4',
        '05' => 'Dante Mic 5',
        '06' => 'Dante Mic 6',
        '07' => 'Dante Mic 7',
        '08' => 'Dante Mic 8',
        '09' => 'Dante In 9',
        '10' => 'Dante In 10',
        '11' => 'Analog In 1',
        '12' => 'Analog In 2',
        '13' => 'USB Input',
        '14' => 'Mobile Input',
        '15' => 'Dante Out 1',
        '16' => 'Dante Out 2',
        '17' => 'Analog Out 1',
        '18' => 'Analog Out 2',
        '19' => 'USB Output',
        '20' => 'Mobile Output',
        '21' => 'Automixer Out',
        '22' => 'AEC Reference',
        '23' => 'Dante Out 3',
        '24' => 'Dante Out 4',
        '25' => 'Dante Out 5',
        '26' => 'Dante Out 6',
        '27' => 'Dante Out 7',
        '28' => 'Dante Out 8',
    ];

    /**
     * Send one or more Shure ASCII commands (< ... >) over TCP port 2202 and collect < REP ... > responses.
     *
     * @param string[] $commands
     * @return string[]
     */
    public function sendBatch(P300Device $device, array $commands, int $readWindowMs = 220): array
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->Port ?: 2202);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Invalid IP address configured for '{$device->Title}'.");
        }

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($ip, $port, $errno, $errstr, 0.75);
        if (!is_resource($fp)) {
            throw new RuntimeException("Unable to connect to P300 at {$ip}:{$port} ({$errstr}).");
        }

        stream_set_timeout($fp, 0, 150_000);

        $wire = '';
        foreach ($commands as $cmd) {
            $trimmed = trim($cmd);
            if ($trimmed === '') {
                continue;
            }
            if (!str_starts_with($trimmed, '<')) {
                $trimmed = '< ' . trim($trimmed, '<> ') . ' >';
            }
            $wire .= $trimmed;
        }

        if (@fwrite($fp, $wire) === false) {
            fclose($fp);
            throw new RuntimeException("Failed writing command(s) to P300 at {$ip}:{$port}.");
        }

        $raw = '';
        $deadline = microtime(true) + ($readWindowMs / 1000);
        while (!feof($fp) && microtime(true) < $deadline) {
            $chunk = @fread($fp, 4096);
            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;
                if (substr_count($raw, '>') >= count($commands)) {
                    // Brief grace window for multi-line REP bursts
                    usleep(25_000);
                }
            } else {
                usleep(15_000);
            }
        }
        fclose($fp);

        preg_match_all('/<[^>]+>/', $raw, $matches);
        return $matches[0] ?? [];
    }

    public function sendSingle(
        P300Device $device,
        string $command,
        string $auditAction = 'Command',
        ?string $auditSummary = null
    ): array {
        $formatted = str_starts_with(trim($command), '<')
            ? trim($command)
            : '< ' . trim($command, '<> ') . ' >';

        $summary = $auditSummary ?: "{$device->Title}: {$formatted}";

        try {
            $replies = $this->sendBatch($device, [$formatted], 200);
            $auditArgs = [$auditAction, $summary, $formatted, true, null, $device];
            $this->extend('onPanelAudit', ...$auditArgs);
            return $replies;
        } catch (RuntimeException $e) {
            $auditArgs = [$auditAction, $summary, $formatted, false, $e->getMessage(), $device];
            $this->extend('onPanelAudit', ...$auditArgs);
            throw $e;
        }
    }

    /**
     * Query live state from the P300 over TCP 2202:
     * - System Automixer Mute (Ch 21)
     * - Active Preset & Preset Names (1..PresetCount)
     * - Channel Names, Audio Mute, and High-Res Gain (0000-1400 -> -110.0 to +30.0 dB)
     */
    public function fetchDeviceState(P300Device $device): array
    {
        $channels = $device->getConfiguredChannelList();
        $presetCount = max(1, min(10, (int) ($device->PresetCount ?: 10)));

        $queries = [
            '< GET DEVICE_ID >',
            '< GET 21 AUTOMXR_MUTE >',
            '< GET PRESET >',
            '< GET 00 CHAN_NAME >',
            '< GET 00 AUDIO_MUTE >',
            '< GET 00 AUDIO_GAIN_HI_RES >',
        ];

        for ($p = 1; $p <= $presetCount; $p++) {
            $queries[] = "< GET PRESET{$p} >";
        }

        $replies = $this->sendBatch($device, $queries, 350);

        $deviceId = '';
        $automixerMuted = false;
        $activePreset = 0;
        $presetNames = [];
        $chanNames = [];
        $chanMutes = [];
        $chanGains = [];

        foreach ($replies as $rep) {
            if (preg_match('/^<\s*REP\s+DEVICE_ID\s+\{(.*?)\}\s*>$/s', $rep, $m)) {
                $deviceId = trim($m[1]);
            } elseif (preg_match('/^<\s*REP\s+21\s+AUTOMXR_MUTE\s+(ON|OFF)\s*>$/i', $rep, $m)) {
                $automixerMuted = strtoupper($m[1]) === 'ON';
            } elseif (preg_match('/^<\s*REP\s+PRESET\s+(\d{1,2})\s*>$/i', $rep, $m)) {
                $activePreset = (int) $m[1];
            } elseif (preg_match('/^<\s*REP\s+PRESET(\d{1,2})\s+\{(.*?)\}\s*>$/s', $rep, $m)) {
                $idx = (int) $m[1];
                $label = trim($m[2]);
                $presetNames[$idx] = $label !== '' ? $label : "Preset {$idx}";
            } elseif (preg_match('/^<\s*REP\s+(\d{2})\s+CHAN_NAME\s+\{(.*?)\}\s*>$/s', $rep, $m)) {
                $chanNames[$m[1]] = trim($m[2]);
            } elseif (preg_match('/^<\s*REP\s+(\d{2})\s+AUDIO_MUTE\s+(ON|OFF)\s*>$/i', $rep, $m)) {
                $chanMutes[$m[1]] = strtoupper($m[2]) === 'ON';
            } elseif (preg_match('/^<\s*REP\s+(\d{2})\s+AUDIO_GAIN_HI_RES\s+(\d{4})\s*>$/i', $rep, $m)) {
                $chanGains[$m[1]] = (int) $m[2];
            }
        }

        $presets = [];
        for ($p = 1; $p <= $presetCount; $p++) {
            $presets[] = [
                'number' => $p,
                'name'   => $presetNames[$p] ?? "Preset {$p}",
                'active' => $activePreset === $p,
            ];
        }

        $channelData = [];
        foreach ($channels as $ch) {
            $rawGain = $chanGains[$ch] ?? 1100; // 1100 = 0.0 dB
            $db = round(($rawGain - 1100) / 10, 1);
            $hwName = $chanNames[$ch] ?? '';
            $defaultName = self::DEFAULT_CHANNEL_LABELS[$ch] ?? "Channel {$ch}";

            $channelData[] = [
                'channel'     => $ch,
                'name'        => $hwName !== '' ? $hwName : $defaultName,
                'defaultName' => $defaultName,
                'group'       => $this->getChannelGroup($ch),
                'muted'       => $chanMutes[$ch] ?? false,
                'gainRaw'     => $rawGain,
                'gainDb'      => $db,
            ];
        }

        return [
            'online'         => true,
            'deviceId'       => $deviceId,
            'automixerMuted' => $automixerMuted,
            'activePreset'   => $activePreset,
            'presets'        => $presets,
            'channels'       => $channelData,
        ];
    }

    public function toggleAutomixerMute(P300Device $device, string $state = 'TOGGLE'): bool
    {
        $mode = strtoupper(trim($state));
        if (!in_array($mode, ['ON', 'OFF', 'TOGGLE'], true)) {
            throw new RuntimeException("Invalid mute mode '{$state}'.");
        }

        $replies = $this->sendSingle(
            $device,
            "< SET 21 AUTOMXR_MUTE {$mode} >",
            'Mute',
            "{$device->Title}: System Automixer Mute ({$mode})"
        );

        foreach ($replies as $rep) {
            if (preg_match('/^<\s*REP\s+21\s+AUTOMXR_MUTE\s+(ON|OFF)\s*>$/i', $rep, $m)) {
                return strtoupper($m[1]) === 'ON';
            }
        }

        return $mode === 'ON';
    }

    public function setChannelMute(P300Device $device, string $channel, string $state = 'TOGGLE'): bool
    {
        $ch = $this->normalizeChannel($channel);
        $mode = strtoupper(trim($state));
        if (!in_array($mode, ['ON', 'OFF', 'TOGGLE'], true)) {
            throw new RuntimeException("Invalid channel mute mode '{$state}'.");
        }

        $replies = $this->sendSingle(
            $device,
            "< SET {$ch} AUDIO_MUTE {$mode} >",
            'ChannelMute',
            "{$device->Title}: Ch {$ch} Mute ({$mode})"
        );

        foreach ($replies as $rep) {
            if (preg_match('/^<\s*REP\s+' . $ch . '\s+AUDIO_MUTE\s+(ON|OFF)\s*>$/i', $rep, $m)) {
                return strtoupper($m[1]) === 'ON';
            }
        }

        return $mode === 'ON';
    }

    public function setChannelGainDb(P300Device $device, string $channel, float $gainDb): float
    {
        $ch = $this->normalizeChannel($channel);
        $clampedDb = max(-110.0, min(30.0, $gainDb));
        $raw = (int) round(($clampedDb * 10) + 1100);
        $rawStr = str_pad((string) max(0, min(1400, $raw)), 4, '0', STR_PAD_LEFT);

        $replies = $this->sendSingle(
            $device,
            "< SET {$ch} AUDIO_GAIN_HI_RES {$rawStr} >",
            'Gain',
            sprintf('%s: Ch %s Gain %.1f dB', $device->Title, $ch, $clampedDb)
        );

        foreach ($replies as $rep) {
            if (preg_match('/^<\s*REP\s+' . $ch . '\s+AUDIO_GAIN_HI_RES\s+(\d{4})\s*>$/i', $rep, $m)) {
                return round(((int) $m[1] - 1100) / 10, 1);
            }
        }

        return $clampedDb;
    }

    public function recallPreset(P300Device $device, int $presetNumber): int
    {
        if ($presetNumber < 1 || $presetNumber > 10) {
            throw new RuntimeException('Preset number must be between 1 and 10.');
        }

        $nn = str_pad((string) $presetNumber, 2, '0', STR_PAD_LEFT);
        $replies = $this->sendSingle(
            $device,
            "< SET PRESET {$nn} >",
            'Preset',
            "{$device->Title}: Recall Preset {$nn}"
        );

        foreach ($replies as $rep) {
            if (preg_match('/^<\s*REP\s+PRESET\s+(\d{1,2})\s*>$/i', $rep, $m)) {
                return (int) $m[1];
            }
        }

        return $presetNumber;
    }

    public function flashLights(P300Device $device): void
    {
        $this->sendSingle(
            $device,
            '< SET FLASH ON >',
            'Locate',
            "{$device->Title}: Flash Front Panel LEDs (30s)"
        );
    }

    private function normalizeChannel(string $channel): string
    {
        $num = (int) trim($channel);
        if ($num < 0 || $num > 28 || $num === 21 || $num === 22) {
            throw new RuntimeException("Invalid P300 audio channel '{$channel}'.");
        }
        return str_pad((string) $num, 2, '0', STR_PAD_LEFT);
    }

    private function getChannelGroup(string $ch): string
    {
        $num = (int) $ch;
        if ($num >= 1 && $num <= 8) {
            return 'Dante Mic Inputs';
        }
        if ($num >= 9 && $num <= 14) {
            return 'Line / USB / Mobile Inputs';
        }
        return 'Outputs';
    }
}
PHP

echo "=== 5. Creating P300/src/Controller/P300Controller.php ==="
cat <<'PHP' > P300/src/Controller/P300Controller.php
<?php

namespace App\P300\Controller;

use App\Model\Location;
use App\P300\Model\P300Device;
use App\P300\Service\P300Service;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\SecurityToken;
use Throwable;

class P300Controller extends Controller
{
    private static array $allowed_actions = [
        'index',
        'status',
        'mute',
        'gain',
        'preset',
        'flash',
        'command',
    ];

    private static array $url_handlers = [
        'status'  => 'status',
        'mute'    => 'mute',
        'gain'    => 'gain',
        'preset'  => 'preset',
        'flash'   => 'flash',
        'command' => 'command',
        ''        => 'index',
    ];

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();

        if (!PanelSecurityGuard::isIPAllowed($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'IP not allowed.'], 403));
        }

        if (!PanelSecurityGuard::isAuthenticated($request)) {
            if (str_starts_with($request->getURL(), 'api/')) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Auth required.'], 401));
            }
            $this->redirect('/panel');
            return;
        }
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        return HTTPResponse::create(
            $this->renderWith(['App\\P300\\Controller\\TouchPanelP300', 'Page'])
        );
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $locations = array_map(fn(Location $l) => [
            'id'    => (int) $l->ID,
            'title' => $l->Title,
        ], Location::get()->toArray());

        $service = P300Service::singleton();
        $devices = [];

        foreach (P300Device::get() as $dev) {
            try {
                $live = $service->fetchDeviceState($dev);
            } catch (Throwable $e) {
                $live = [
                    'online'         => false,
                    'error'          => $e->getMessage(),
                    'deviceId'       => '',
                    'automixerMuted' => false,
                    'activePreset'   => 0,
                    'presets'        => [],
                    'channels'       => array_map(fn(string $ch) => [
                        'channel'     => $ch,
                        'name'        => P300Service::DEFAULT_CHANNEL_LABELS[$ch] ?? "Channel {$ch}",
                        'defaultName' => P300Service::DEFAULT_CHANNEL_LABELS[$ch] ?? "Channel {$ch}",
                        'group'       => (int) $ch <= 8 ? 'Dante Mic Inputs' : ((int) $ch <= 14 ? 'Line / USB / Mobile Inputs' : 'Outputs'),
                        'muted'       => false,
                        'gainRaw'     => 1100,
                        'gainDb'      => 0.0,
                    ], $dev->getConfiguredChannelList()),
                ];
            }

            $devices[] = array_merge([
                'id'         => (int) $dev->ID,
                'title'      => (string) $dev->Title,
                'ip'         => (string) $dev->IPAddress,
                'port'       => (int) ($dev->Port ?: 2202),
                'locationId' => (int) $dev->LocationID,
            ], $live);
        }

        return $this->jsonResponse([
            'success'   => true,
            'csrfToken' => SecurityToken::inst()->getValue(),
            'locations' => $locations,
            'devices'   => $devices,
        ]);
    }

    public function mute(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $target = (string) ($data['target'] ?? 'automixer'); // 'automixer' or 2-digit channel
            $state = (string) ($data['state'] ?? 'TOGGLE');
            $service = P300Service::singleton();

            if ($target === 'automixer' || $target === '21') {
                $muted = $service->toggleAutomixerMute($device, $state);
                return $this->jsonResponse(['success' => true, 'target' => 'automixer', 'muted' => $muted]);
            }

            $muted = $service->setChannelMute($device, $target, $state);
            return $this->jsonResponse(['success' => true, 'target' => $target, 'muted' => $muted]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function gain(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $channel = (string) ($data['channel'] ?? '');
            $gainDb = (float) ($data['gain_db'] ?? 0.0);

            $appliedDb = P300Service::singleton()->setChannelGainDb($device, $channel, $gainDb);
            return $this->jsonResponse(['success' => true, 'channel' => $channel, 'gainDb' => $appliedDb]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function preset(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $presetNumber = (int) ($data['preset'] ?? 0);

            $active = P300Service::singleton()->recallPreset($device, $presetNumber);
            return $this->jsonResponse(['success' => true, 'activePreset' => $active]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function flash(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            P300Service::singleton()->flashLights($device);
            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function command(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $raw = trim((string) ($data['command'] ?? ''));
            if ($raw === '') {
                return $this->jsonResponse(['success' => false, 'error' => 'Command cannot be empty.'], 400);
            }

            $replies = P300Service::singleton()->sendSingle($device, $raw);
            return $this->jsonResponse(['success' => true, 'replies' => $replies]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function findDevice(int $id): P300Device
    {
        $device = P300Device::get()->byID($id);
        if (!$device) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'P300 device not found.'], 404));
        }
        return $device;
    }

    private function parseJson(HTTPRequest $request): array
    {
        $body = $request->getBody();
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonResponse(array $payload, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_UNESCAPED_SLASHES))
            ->setStatusCode($status)
            ->addHeader('Content-Type', 'application/json');
    }
}
PHP

echo "=== 6. Creating P300/src/Admin/P300Admin.php ==="
cat <<'PHP' > P300/src/Admin/P300Admin.php
<?php

namespace App\P300\Admin;

use App\P300\Model\P300AuditLog;
use App\P300\Model\P300Device;
use SilverStripe\Admin\ModelAdmin;

class P300Admin extends ModelAdmin
{
    private static string $url_segment = 'p300-dsp';

    private static string $menu_title = 'Shure P300 DSP';

    private static string $menu_icon_class = 'font-icon-sliders';

    private static array $managed_models = [
        P300Device::class,
        P300AuditLog::class,
    ];
}
PHP

echo "=== 7. Creating TouchPanelP300.ss & PanelP300JS.ss ==="
cat <<'HTML' > P300/templates/App/P300/Controller/TouchPanelP300.ss
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> - Shure P300 DSP</title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=29">
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=29">
    <style>
        .p300-device-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .p300-hero-card {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: center;
            justify-content: space-between;
        }
        body[data-theme="light"] .p300-hero-card { background: rgba(255, 255, 255, 0.9); }
        .p300-master-mute-btn {
            min-height: 56px;
            padding: 0 26px;
            border-radius: 12px;
            border: 2px solid #22c55e;
            background: rgba(34, 197, 94, 0.16);
            color: #4ade80;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .p300-master-mute-btn.is-muted {
            border-color: #ef4444;
            background: #dc2626;
            color: #ffffff;
            box-shadow: 0 0 18px rgba(239, 68, 68, 0.45);
        }
        .p300-presets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .p300-preset-btn {
            padding: 14px 12px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(30, 41, 59, 0.75);
            color: inherit;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
        }
        body[data-theme="light"] .p300-preset-btn { background: #ffffff; }
        .p300-preset-btn.active {
            border-color: #38bdf8;
            background: rgba(14, 165, 233, 0.22);
        }
        .p300-channels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
        }
        .p300-chan-card {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 14px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        body[data-theme="light"] .p300-chan-card { background: rgba(255, 255, 255, 0.92); }
        .p300-chan-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
        .p300-chan-num { font-size:0.75rem; opacity:0.7; font-weight:600; }
        .p300-chan-name { font-size:1rem; font-weight:700; }
        .p300-db-readout { font-family: monospace; font-size: 0.9rem; font-weight: 700; }
        .p300-slider { width: 100%; accent-color: #38bdf8; height: 28px; cursor: pointer; }
        .p300-chan-mute {
            padding: 10px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: rgba(51, 65, 85, 0.5);
            color: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .p300-chan-mute.is-muted {
            background: #dc2626;
            border-color: #ef4444;
            color: #fff;
        }
    </style>
</head>
<body class="audio-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="p300ConnectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Shure IntelliMix P300 DSP</div>
            </div>
        </div>

        <nav class="nav-tabs">
            <% loop $PanelNavigation %>
                <a href="$Route" class="nav-pill <% if $IsActive %>active<% end_if %>">$Title</a>
            <% end_loop %>
            <a href="/panel/lock" class="nav-pill lock-btn">Lock Panel</a>
        </nav>
    </header>

    <main class="panel-main">
        <div class="p300-device-bar">
            <div class="room-tabs" id="p300DeviceTabs"></div>
            <button type="button" class="nav-pill" onclick="flashActiveP300()">Locate / Flash LEDs</button>
        </div>

        <div id="p300Workspace"></div>
    </main>

</div>

<div id="toast"></div>
<% include App\P300\Includes\PanelP300JS %>
</body>
</html>
HTML

cat <<'HTML' > P300/templates/App/P300/Includes/PanelP300JS.ss
<script>
const p300State = {
    csrfToken: '',
    devices: [],
    activeDeviceId: 0
};

function showP300Toast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = isError ? 'show error' : 'show';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.className = ''; }, isError ? 8000 : 2500);
}

async function p300Post(url, payload) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': p300State.csrfToken
        },
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.error || ('HTTP ' + res.status));
    }
    return data;
}

async function loadP300Status() {
    try {
        const res = await fetch('/api/p300/status');
        if (res.status === 401) { window.location.href = '/panel'; return; }
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load P300 status');

        p300State.csrfToken = data.csrfToken || '';
        p300State.devices = data.devices || [];

        if (!p300State.devices.some(d => d.id === p300State.activeDeviceId) && p300State.devices.length > 0) {
            p300State.activeDeviceId = p300State.devices[0].id;
        }

        renderP300();
    } catch (err) {
        document.getElementById('p300ConnectionDot').style.backgroundColor = '#ef4444';
        showP300Toast(err.message || 'Unable to reach P300 API', true);
    }
}

function getActiveP300() {
    return p300State.devices.find(d => d.id === p300State.activeDeviceId) || null;
}

async function toggleAutomixerMute() {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        const data = await p300Post('/api/p300/mute', {
            device_id: dev.id,
            target: 'automixer',
            state: 'TOGGLE'
        });
        dev.automixerMuted = !!data.muted;
        renderP300();
        showP300Toast(dev.title + (dev.automixerMuted ? ': System Muted' : ': System Unmuted'));
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function toggleChannelMute(channel) {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        const data = await p300Post('/api/p300/mute', {
            device_id: dev.id,
            target: channel,
            state: 'TOGGLE'
        });
        const chObj = dev.channels.find(c => c.channel === channel);
        if (chObj) chObj.muted = !!data.muted;
        renderP300();
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function setChannelGain(channel, dbVal) {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        const data = await p300Post('/api/p300/gain', {
            device_id: dev.id,
            channel: channel,
            gain_db: parseFloat(dbVal)
        });
        const chObj = dev.channels.find(c => c.channel === channel);
        if (chObj) chObj.gainDb = data.gainDb;
        renderP300();
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function recallP300Preset(presetNum, presetName) {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        await p300Post('/api/p300/preset', {
            device_id: dev.id,
            preset: presetNum
        });
        showP300Toast(dev.title + ': Recalled ' + presetName);
        await loadP300Status();
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function flashActiveP300() {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        await p300Post('/api/p300/flash', { device_id: dev.id });
        showP300Toast(dev.title + ': Flashing front-panel LEDs for 30s');
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

function renderP300() {
    const tabs = document.getElementById('p300DeviceTabs');
    const workspace = document.getElementById('p300Workspace');
    tabs.innerHTML = '';
    workspace.innerHTML = '';

    if (p300State.devices.length === 0) {
        workspace.innerHTML = '<div class="p300-hero-card">No Shure P300 DSP configured yet. Add one in CMS &rarr; Shure P300 DSP.</div>';
        return;
    }

    p300State.devices.forEach(dev => {
        const btn = document.createElement('button');
        btn.className = 'room-tab' + (dev.id === p300State.activeDeviceId ? ' active' : '');
        btn.textContent = dev.title + (dev.online ? '' : ' (Offline)');
        btn.onclick = () => { p300State.activeDeviceId = dev.id; renderP300(); };
        tabs.appendChild(btn);
    });

    const dev = getActiveP300();
    if (!dev) return;

    document.getElementById('p300ConnectionDot').style.backgroundColor = dev.online ? '#22c55e' : '#ef4444';

    // Hero card: Device info + Master Automixer Mute (Ch 21)
    const hero = document.createElement('div');
    hero.className = 'p300-hero-card';

    const info = document.createElement('div');
    const titleEl = document.createElement('h2');
    titleEl.style.margin = '0 0 4px 0';
    titleEl.textContent = dev.title + (dev.deviceId ? ' (' + dev.deviceId + ')' : '');

    const subEl = document.createElement('div');
    subEl.style.opacity = '0.75';
    subEl.style.fontSize = '0.9rem';
    subEl.textContent = dev.online
        ? 'Connected via TCP ' + dev.ip + ':' + dev.port
        : 'Offline: ' + (dev.error || 'Unable to reach ' + dev.ip + ':' + dev.port);

    info.append(titleEl, subEl);

    const muteBtn = document.createElement('button');
    muteBtn.type = 'button';
    muteBtn.className = 'p300-master-mute-btn' + (dev.automixerMuted ? ' is-muted' : '');
    muteBtn.textContent = dev.automixerMuted ? 'SYSTEM MUTED (Click to Unmute)' : 'SYSTEM LIVE (Mute All Mics)';
    muteBtn.onclick = toggleAutomixerMute;

    hero.append(info, muteBtn);
    workspace.appendChild(hero);

    // Hardware Presets (01-10)
    if (Array.isArray(dev.presets) && dev.presets.length > 0) {
        const pHeader = document.createElement('h3');
        pHeader.textContent = 'Hardware Presets';
        workspace.appendChild(pHeader);

        const pGrid = document.createElement('div');
        pGrid.className = 'p300-presets-grid';

        dev.presets.forEach(p => {
            const pBtn = document.createElement('button');
            pBtn.type = 'button';
            pBtn.className = 'p300-preset-btn' + (p.active ? ' active' : '');
            pBtn.textContent = String(p.number).padStart(2, '0') + ' · ' + p.name;
            pBtn.onclick = () => recallP300Preset(p.number, p.name);
            pGrid.appendChild(pBtn);
        });

        workspace.appendChild(pGrid);
    }

    // Channel Strips
    const cHeader = document.createElement('h3');
    cHeader.textContent = 'Channels & Gain';
    workspace.appendChild(cHeader);

    const cGrid = document.createElement('div');
    cGrid.className = 'p300-channels-grid';

    (dev.channels || []).forEach(ch => {
        const card = document.createElement('div');
        card.className = 'p300-chan-card';

        const top = document.createElement('div');
        top.className = 'p300-chan-top';

        const left = document.createElement('div');
        const num = document.createElement('div');
        num.className = 'p300-chan-num';
        num.textContent = 'CH ' + ch.channel + ' · ' + ch.group;

        const name = document.createElement('div');
        name.className = 'p300-chan-name';
        name.textContent = ch.name;
        left.append(num, name);

        const dbReadout = document.createElement('div');
        dbReadout.className = 'p300-db-readout';
        dbReadout.textContent = (ch.gainDb > 0 ? '+' : '') + Number(ch.gainDb).toFixed(1) + ' dB';

        top.append(left, dbReadout);

        const slider = document.createElement('input');
        slider.type = 'range';
        slider.className = 'p300-slider';
        slider.min = '-80';
        slider.max = '20';
        slider.step = '0.5';
        slider.value = String(Math.max(-80, Math.min(20, ch.gainDb)));
        slider.oninput = () => {
            const v = parseFloat(slider.value);
            dbReadout.textContent = (v > 0 ? '+' : '') + v.toFixed(1) + ' dB';
        };
        slider.onchange = () => setChannelGain(ch.channel, slider.value);

        const cMute = document.createElement('button');
        cMute.type = 'button';
        cMute.className = 'p300-chan-mute' + (ch.muted ? ' is-muted' : '');
        cMute.textContent = ch.muted ? 'MUTED' : 'Mute Channel';
        cMute.onclick = () => toggleChannelMute(ch.channel);

        card.append(top, slider, cMute);
        cGrid.appendChild(card);
    });

    workspace.appendChild(cGrid);
}

loadP300Status();
setInterval(loadP300Status, 10000);
</script>
HTML

echo "=== 8. Creating P300/_config/module_p300.yml ==="
cat <<'YAML' > P300/_config/module_p300.yml
---
Name: module-p300
Before: '#approutes'
---
SilverStripe\Control\Director:
  rules:
    'panel/p300//$Action/$ID': 'App\P300\Controller\P300Controller'
    'api/p300//$Action/$ID': 'App\P300\Controller\P300Controller'

App\P300\Service\P300Service:
  extensions:
    - App\P300\Extensions\P300AuditExtension
YAML

echo "=== 9. Registering App\\P300\\ in composer.json ==="
docker compose exec -T web php <<'PHP'
<?php
$composerPath = 'composer.json';
$composer = json_decode(file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
$composer['autoload']['psr-4']['App\\P300\\'] = 'P300/src/';
file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
PHP

echo "=== 10. Rebuilding autoloader, database tables & SilverStripe cache ==="
docker compose exec -T web sh -c '
composer dump-autoload &&
rm -rf /tmp/silverstripe-cache* silverstripe-cache* &&
vendor/bin/sake db:build --flush &&
apache2ctl graceful
'

echo "=== Done! Shure P300 DSP module is ready at /panel/p300 and /admin/p300-dsp ==="
