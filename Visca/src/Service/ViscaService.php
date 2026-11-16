<?php

namespace App\Visca\Service;

use SilverStripe\Core\Extensible;

use Exception;

class ViscaService
{
    use Extensible;

    private string $ipAddress;
    private int $port;

    // VISCA Command Headers & Terminators
    private const HEADER = "\x81";
    private const TERMINATOR = "\xFF";

    public function __construct(string $ipAddress, int $port = 52381)
    {
        $this->ipAddress = $ipAddress;
        $this->port = $port;
    }

    /**
     * Executes Pan/Tilt Movement
     * Direction: 'up', 'down', 'left', 'right', 'upleft', 'upright', 'downleft', 'downright', 'stop'
     */
    public function ptzMove(string $direction, int $panSpeed = 10, int $tiltSpeed = 10): bool
    {
        $ps = pack('C', min(24, max(1, $panSpeed)));
        $ts = pack('C', min(20, max(1, $tiltSpeed)));

        // VISCA Directions: Pan (Left=01, Right=02, Stop=03), Tilt (Up=01, Down=02, Stop=03)
        $dirBytes = match($direction) {
            'up'        => "\x03\x01",
            'down'      => "\x03\x02",
            'left'      => "\x01\x03",
            'right'     => "\x02\x03",
            'upleft'    => "\x01\x01",
            'upright'   => "\x02\x01",
            'downleft'  => "\x01\x02",
            'downright' => "\x02\x02",
            default     => "\x03\x03", // stop
        };

        // 81 01 06 01 [PanSpeed] [TiltSpeed] [PanDir] [TiltDir] FF
        $payload = self::HEADER . "\x01\x06\x01" . $ps . $ts . $dirBytes . self::TERMINATOR;
        return $this->sendUDP($payload);
    }

    /**
     * Executes Zoom: 'tele' (zoom in), 'wide' (zoom out), 'stop'
     */
    public function ptzZoom(string $action): bool
    {
        $zoomByte = match($action) {
            'tele' => "\x22", // Variable speed tele (22-27)
            'wide' => "\x32", // Variable speed wide (32-37)
            default => "\x00", // Stop
        };

        // 81 01 04 07 [ZoomByte] FF
        $payload = self::HEADER . "\x01\x04\x07" . $zoomByte . self::TERMINATOR;
        return $this->sendUDP($payload);
    }

    public function savePreset(int $presetId): bool
    {
        $presetHex = pack('C', min(255, max(0, $presetId)));
        // 81 01 04 3F 01 [PresetID] FF
        $payload = self::HEADER . "\x01\x04\x3F\x01" . $presetHex . self::TERMINATOR;
        return $this->sendUDP($payload);
    }

    public function recallPreset(int $presetId): bool
    {
        $presetHex = pack('C', min(255, max(0, $presetId)));
        // 81 01 04 3F 02 [PresetID] FF
        $payload = self::HEADER . "\x01\x04\x3F\x02" . $presetHex . self::TERMINATOR;
        return $this->sendUDP($payload);
    }

    private function sendUDP(string $payload): bool
    {
        $fp = @fsockopen("udp://{$this->ipAddress}", $this->port, $errno, $errstr);
        if (!$fp) {
            throw new Exception("Camera connection failed: $errstr ($errno)");
        }
        stream_set_timeout($fp, 1);
        fwrite($fp, $payload);
        fclose($fp);
        return true;
    }
}
