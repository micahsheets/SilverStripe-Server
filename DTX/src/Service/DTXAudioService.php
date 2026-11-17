<?php

namespace App\DTX\Service;

use Exception;
use SilverStripe\Core\Extensible;

class DTXAudioService
{
    use Extensible;

    private const MIN_DB = -72.0;
    private const MAX_DB = 12.0;
    private const CURVE = 5.0; // From idc.js curve mapping

    // Aurora DTX Protocol Message Types
    private const MSG_SET_PARAM = 0x21;
    private const MSG_GET_PARAM = 0x22;
    private const MSG_SET_PRESET = 0x13;

    private string $ipAddress;
    private int $port;

    public function __construct(string $ipAddress, int $port = 5000)
    {
        $this->ipAddress = $ipAddress;
        $this->port = $port > 0 ? $port : 5000;
    }

    /**
     * Converts a 0-100 UI slider value to -72dB -> +12dB
     */
    public static function sliderToDecibels(float $sliderValue): float
    {
        $sliderValue = max(0, min(100, $sliderValue));
        $fraction = $sliderValue / 100.0;

        return self::MIN_DB + (self::MAX_DB - self::MIN_DB) * pow($fraction, self::CURVE / 10.0);
    }

    /**
     * Converts raw DSP decibels back to a 0-100 UI slider value
     */
    public static function decibelsToSlider(float $decibels): int
    {
        $decibels = max(self::MIN_DB, min(self::MAX_DB, $decibels));
        $fraction = pow(($decibels - self::MIN_DB) / (self::MAX_DB - self::MIN_DB), 10.0 / self::CURVE);

        return (int) round($fraction * 100);
    }

    /**
     * Queries the Aurora DTX device for live channel labels, gain levels, and mute states.
     * Falls back cleanly to the device model's channel map (DTX-44 = 4x4, DTX-88 = 8x8) if offline.
     */
    public function getDeviceState(string $deviceModel = 'DTX-44'): array
    {
        $channelCount = match (strtoupper(trim($deviceModel))) { 'DTX-64' => 64, 'DTX-32' => 32, 'DTX-88' => 8, default => (stripos($deviceModel, '64') !== false ? 64 : (stripos($deviceModel, '32') !== false ? 32 : (stripos($deviceModel, '88') !== false ? 8 : 4))) };
        $channels = [];

        // Build baseline channel table for Inputs (ioType=0) and Outputs (ioType=1)
        for ($ch = 1; $ch <= $channelCount; $ch++) {
            $channels["in_{$ch}"] = [
                'id'      => "in_{$ch}",
                'ioType'  => 0,
                'channel' => $ch,
                'group'   => 'Input',
                'label'   => "Input {$ch}",
                'level'   => 70,
                'db'      => round(self::sliderToDecibels(70), 1),
                'muted'   => false,
            ];
        }
        for ($ch = 1; $ch <= $channelCount; $ch++) {
            $channels["out_{$ch}"] = [
                'id'      => "out_{$ch}",
                'ioType'  => 1,
                'channel' => $ch,
                'group'   => 'Output',
                'label'   => ($ch === 1 && $channelCount === 4) ? 'Master Out 1' : "Output {$ch}",
                'level'   => 50,
                'db'      => round(self::sliderToDecibels(50), 1),
                'muted'   => false,
            ];
        }

        $online = false;

        // 1. Attempt ASCII status/channel name query on UDP control port & Aurora port 6970
        $asciiDump = $this->queryAsciiStatus();
        if ($asciiDump !== '') {
            $online = true;
            $this->parseAsciiChannelState($asciiDump, $channels);
        }

        // 2. Attempt binary MSG_GET_PARAM (0x22) queries for gain & mute per channel
        $fp = @fsockopen("udp://{$this->ipAddress}", $this->port, $errno, $errstr, 0.35);
        if ($fp) {
            stream_set_timeout($fp, 0, 120000); // 120ms per read
            foreach ($channels as $key => &$info) {
                $io = (int) $info['ioType'];
                $chNum = (int) $info['channel'];

                // Query Gain (0x0003 = Input Gain, 0x0004 = Output Gain)
                $gainParam = ($io === 0) ? 0x0003 : 0x0004;
                $gainResp = $this->sendAndReadBinary($fp, self::MSG_GET_PARAM, $gainParam, $chNum);
                if ($gainResp !== null) {
                    $online = true;
                    // Signed 16-bit integer scaled by 10 (tenths of a dB)
                    $rawSigned = ($gainResp['value'] >= 32768) ? ($gainResp['value'] - 65536) : $gainResp['value'];
                    $db = max(self::MIN_DB, min(self::MAX_DB, $rawSigned / 10.0));
                    $info['db'] = round($db, 1);
                    $info['level'] = self::decibelsToSlider($db);
                }

                // Query Mute (0x0001 = Input Mute, 0x0002 = Output Mute)
                $muteParam = ($io === 0) ? 0x0001 : 0x0002;
                $muteResp = $this->sendAndReadBinary($fp, self::MSG_GET_PARAM, $muteParam, $chNum);
                if ($muteResp !== null) {
                    $online = true;
                    $info['muted'] = ((int) $muteResp['value']) === 1;
                }
            }
            unset($info);
            fclose($fp);
        }

        return [
            'online'   => $online,
            'model'    => $deviceModel,
            'channels' => array_values($channels),
        ];
    }

    /**
     * Parses channel identifiers like "in_1", "out_2", "master", "mic1", or numeric "1"
     * into [ioType (0=In, 1=Out), channelNumber (1-based)]
     */
    public static function parseChannelKey(string $channelKey): array
    {
        $key = strtolower(trim($channelKey));
        if ($key === 'master') {
            return [1, 1];
        }
        if ($key === 'mic1') {
            return [0, 1];
        }
        if (preg_match('/^(in|input)[_-]?(\d+)$/', $key, $m)) {
            return [0, max(1, (int) $m[2])];
        }
        if (preg_match('/^(out|output)[_-]?(\d+)$/', $key, $m)) {
            return [1, max(1, (int) $m[2])];
        }
        if (is_numeric($key)) {
            return [0, max(1, (int) $key)];
        }
        return [0, 1];
    }

    public function setChannelVolume(string $channelKey, float $sliderValue): bool
    {
        [$ioType, $channel] = self::parseChannelKey($channelKey);
        return $this->setVolume($ioType, $channel, (int) round($sliderValue));
    }

    public function setChannelMute(string $channelKey, bool $muteState): bool
    {
        [$ioType, $channel] = self::parseChannelKey($channelKey);
        return $this->setMute($ioType, $channel, $muteState);
    }

    /**
     * Mutes or Unmutes a specific channel
     * @param int $ioType 0 for Input, 1 for Output
     */
    public function setMute(int $ioType, int $channel, bool $muteState): bool
    {
        $paramId = ($ioType === 0) ? 0x0001 : 0x0002;
        $value = $muteState ? 1 : 0;

        return $this->sendBinaryUDP(self::MSG_SET_PARAM, $paramId, $channel, $value);
    }

    /**
     * Sets the volume of a specific channel based on a 0-100 UI slider input
     */
    public function setVolume(int $ioType, int $channel, int $sliderValue): bool
    {
        $decibels = self::sliderToDecibels($sliderValue);
        $dspValue = (int) round($decibels * 10);
        if ($dspValue < 0) {
            $dspValue += 65536; // Two's complement 16-bit unsigned for pack('v')
        }

        $paramId = ($ioType === 0) ? 0x0003 : 0x0004;

        return $this->sendBinaryUDP(self::MSG_SET_PARAM, $paramId, $channel, $dspValue);
    }

    /**
     * Triggers a preset recall on the DTX hardware
     */
    public function recallPreset(int $presetId): bool
    {
        return $this->sendBinaryUDP(self::MSG_SET_PRESET, 0x0000, 0x0000, $presetId);
    }

    private function queryAsciiStatus(): string
    {
        $collected = '';
        $ports = array_unique([$this->port, 6970]);
        foreach ($ports as $p) {
            $fp = @fsockopen("udp://{$this->ipAddress}", $p, $errno, $errstr, 0.25);
            if (!$fp) {
                continue;
            }
            stream_set_timeout($fp, 0, 180000);
            @fwrite($fp, "get status\rget channels\rget labels\r");
            $chunk = @fread($fp, 4096);
            if (is_string($chunk) && $chunk !== '') {
                $collected .= "\n" . $chunk;
            }
            fclose($fp);
        }
        return trim($collected);
    }

    private function parseAsciiChannelState(string $raw, array &$channels): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Match labels like: in1_name=Podium Mic, input1_label="Wireless 1", out2_name=Main Speakers
            if (preg_match('/^(in|input|out|output)[_-]?(\d+)[_-]?(name|label)\s*[:=]\s*(.+)$/i', $line, $m)) {
                $prefix = (stripos($m[1], 'out') === 0) ? 'out' : 'in';
                $key = $prefix . '_' . (int) $m[2];
                $label = trim($m[4], " \t\n\r\0\x0B\"'");
                if (isset($channels[$key]) && $label !== '') {
                    $channels[$key]['label'] = $label;
                }
            }

            // Match gain/level like: in1_gain=-6.5, out1_level=75
            if (preg_match('/^(in|input|out|output)[_-]?(\d+)[_-]?(gain|db|level|vol)\s*[:=]\s*(-?\d+(?:\.\d+)?)$/i', $line, $m)) {
                $prefix = (stripos($m[1], 'out') === 0) ? 'out' : 'in';
                $key = $prefix . '_' . (int) $m[2];
                $metric = strtolower($m[3]);
                $val = (float) $m[4];
                if (isset($channels[$key])) {
                    if ($metric === 'level' || $metric === 'vol') {
                        $lvl = (int) max(0, min(100, round($val)));
                        $channels[$key]['level'] = $lvl;
                        $channels[$key]['db'] = round(self::sliderToDecibels($lvl), 1);
                    } else {
                        $db = max(self::MIN_DB, min(self::MAX_DB, $val));
                        $channels[$key]['db'] = round($db, 1);
                        $channels[$key]['level'] = self::decibelsToSlider($db);
                    }
                }
            }

            // Match mute like: in1_mute=1, out2_mute=on
            if (preg_match('/^(in|input|out|output)[_-]?(\d+)[_-]?mute\s*[:=]\s*(\w+)$/i', $line, $m)) {
                $prefix = (stripos($m[1], 'out') === 0) ? 'out' : 'in';
                $key = $prefix . '_' . (int) $m[2];
                if (isset($channels[$key])) {
                    $channels[$key]['muted'] = filter_var($m[3], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
    }

    private function sendAndReadBinary($fp, int $msgType, int $paramId, int $channel): ?array
    {
        $payload = pack('vvvvvv', 0x0000, $msgType, $paramId, $channel, 0x0000, 0x0000);
        if (@fwrite($fp, $payload) === false) {
            return null;
        }
        $resp = @fread($fp, 12);
        if (!is_string($resp) || strlen($resp) < 10) {
            return null;
        }
        $unpacked = @unpack('vsync/vmsgType/vparamId/vchannel/vvalue', $resp);
        return is_array($unpacked) ? $unpacked : null;
    }

    private function sendBinaryUDP(int $msgType, int $paramId, int $channel, int $value): bool
    {
        $payload = pack(
            'vvvvvv',
            0x0000,
            $msgType,
            $paramId,
            $channel,
            $value & 0xFFFF,
            0x0000
        );

        $fp = @fsockopen("udp://{$this->ipAddress}", $this->port, $errno, $errstr, 1);
        if (!$fp) {
            throw new Exception("UDP Connection failed: $errstr ($errno)");
        }

        stream_set_timeout($fp, 1);
        fwrite($fp, $payload);
        fclose($fp);
        return true;
    }
}
