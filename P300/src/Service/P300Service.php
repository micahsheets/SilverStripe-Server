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
