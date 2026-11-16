<?php

namespace App\DTX\Service;

use SilverStripe\Core\Extensible;

use Exception;

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

    public function __construct(string $ipAddress, int $port = 50000)
    {
        $this->ipAddress = $ipAddress;
        // Defaulting to 50000; adjust if your DTX network config differs
        $this->port = $port;
    }

    /**
     * Recreates the idc.js _sliderToDecibels math
     * Converts a 0-100 UI slider value to -72dB -> +12dB
     */
    public static function sliderToDecibels(float $sliderValue): float
    {
        $sliderValue = max(0, min(100, $sliderValue));
        $fraction = $sliderValue / 100.0;

        // db = minDb + (maxDb - minDb) * (fraction^(curve/10))
        return self::MIN_DB + (self::MAX_DB - self::MIN_DB) * pow($fraction, self::CURVE / 10.0);
    }

    /**
     * Recreates the idc.js _decibelsToSlider math
     * Converts raw DSP decibels back to a 0-100 UI slider value
     */
    public static function decibelsToSlider(float $decibels): int
    {
        $decibels = max(self::MIN_DB, min(self::MAX_DB, $decibels));
        $fraction = pow(($decibels - self::MIN_DB) / (self::MAX_DB - self::MIN_DB), 10.0 / self::CURVE);

        return (int) round($fraction * 100);
    }

    /**
     * Mutes or Unmutes a specific channel
     * @param int $ioType 0 for Input, 1 for Output
     */
    public function setMute(int $ioType, int $channel, bool $muteState): bool
    {
        // 0x0001 is typically the param ID for Mute in DSPs, though this may require tuning
        // based on the exact DTX param table.
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

        // Convert dB to the integer format expected by the DSP (often scaled by 10 or 100)
        // Adjust scaling if the DTX expects fixed-point integers
        $dspValue = (int) round($decibels * 10);

        $paramId = ($ioType === 0) ? 0x0003 : 0x0004; // Gain Param IDs

        return $this->sendBinaryUDP(self::MSG_SET_PARAM, $paramId, $channel, $dspValue);
    }

    /**
     * Triggers a preset recall on the DTX hardware
     */
    public function recallPreset(int $presetId): bool
    {
        return $this->sendBinaryUDP(self::MSG_SET_PRESET, 0x0000, 0x0000, $presetId);
    }

    /**
     * Constructs and sends the 12-byte binary payload to the Aurora DSP
     */
    private function sendBinaryUDP(int $msgType, int $paramId, int $channel, int $value): bool
    {
        // Assemble the byte array (Little Endian format common in ARM/SHARC DSPs)
        // Structure: Header/Sync (4 bytes) | MsgType (2) | ParamID (2) | Channel (2) | Value (2)
        // pack("v") writes a 16-bit little-endian short.
        $payload = pack("vvvvvv",
            0x0000,     // Sync / Header (Placeholder)
            $msgType,   // 0x21 SET, 0x22 GET
            $paramId,   // What we are controlling (Mute, Gain, etc)
            $channel,   // Target channel ID
            $value,     // The new value
            0x0000      // Checksum or padding
        );

        $fp = @fsockopen("udp://{$this->ipAddress}", $this->port, $errno, $errstr);
        if (!$fp) {
            throw new Exception("UDP Connection failed: $errstr ($errno)");
        }

        stream_set_timeout($fp, 1);
        fwrite($fp, $payload);

        // Optional: Read acknowledgment packet for two-way sync
        // $response = fread($fp, 12);

        fclose($fp);
        return true;
    }
}
