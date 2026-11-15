<?php

namespace App\Service;

use App\Model\RodeCasterDevice;
use App\Model\RouteAuditLog;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class RodeCasterOSCService
{
    use Injectable;

    public const ACTION_MAP = [
        'scene'   => '/device/scene',
        'input'   => '/device/input',
        'overlay' => '/device/toggleOverlay',
        'media'   => '/device/media',
    ];

    public const DIRECT_ACTION_MAP = [
        'cut'  => ['/actionCut', 1],
        'auto' => ['/actionAuto', 1],
    ];

    public function triggerAction(RodeCasterDevice $device, string $action, int $index = 0): array
    {
        $action = strtolower(trim($action));

        if ($action === 'toggle_studio' || $action === 'instant' || $action === 'studio') {
            return $this->setSwitchingMode($device, $action);
        }

        if (isset(self::DIRECT_ACTION_MAP[$action])) {
            [$address, $value] = self::DIRECT_ACTION_MAP[$action];
            $this->sendIntMessage($device, $address, $value);

            RouteAuditLog::record(
                'Command',
                "RØDECaster ({$device->Title}): " . strtoupper($action),
                "{$address} {$value}",
                true
            );

            return [
                'device'  => $device->Title,
                'action'  => $action,
                'address' => $address,
                'index'   => $value,
            ];
        }

        if (!isset(self::ACTION_MAP[$action])) {
            throw new RuntimeException("Unsupported RØDECaster action '{$action}'.");
        }

        if ($index < 1 || $index > 64) {
            throw new RuntimeException("Invalid button index '{$index}'.");
        }

        $address = self::ACTION_MAP[$action];
        $this->sendIntMessage($device, $address, $index);

        RouteAuditLog::record(
            'Command',
            "RØDECaster ({$device->Title}): " . ucfirst($action) . " #{$index}",
            "{$address} {$index}",
            true
        );

        return [
            'device'  => $device->Title,
            'action'  => $action,
            'address' => $address,
            'index'   => $index,
        ];
    }

    public function setSwitchingMode(RodeCasterDevice $device, string $requested): array
    {
        $ip = trim((string) $device->IPAddress);
        $fp = $this->openHandshakeSocket($device, $dump);

        try {
            $current = 'studioLeft';
            if (preg_match('/switching_mode="([^"]+)"/', $dump, $m)) {
                $current = $m[1];
            }

            $targetMode = match ($requested) {
                'instant' => 'instant',
                'studio'  => 'studioLeft',
                default   => ($current === 'instant') ? 'studioLeft' : 'instant',
            };

            $packet = $this->buildTcpOscStringPacket('/show/switching_mode', $targetMode);
            $written = @fwrite($fp, $packet);
            if ($written === false || $written < strlen($packet)) {
                throw new RuntimeException("Failed writing OSC packet to {$ip}.");
            }
            fflush($fp);
        } finally {
            fclose($fp);
        }

        RouteAuditLog::record(
            'Command',
            "RØDECaster ({$device->Title}): Mode -> {$targetMode}",
            "/show/switching_mode {$targetMode}",
            true
        );

        return [
            'device'  => $device->Title,
            'action'  => $requested,
            'address' => '/show/switching_mode',
            'mode'    => $targetMode,
        ];
    }

    public function sendIntMessage(RodeCasterDevice $device, string $oscAddress, int $value): void
    {
        $ip = trim((string) $device->IPAddress);
        $fp = $this->openHandshakeSocket($device);

        try {
            $packet = $this->buildTcpOscIntPacket($oscAddress, $value);
            $written = @fwrite($fp, $packet);
            if ($written === false || $written < strlen($packet)) {
                throw new RuntimeException("Failed writing OSC packet to {$ip}.");
            }
            fflush($fp);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Opens a TCP socket to the RØDECaster Video S and drains the initial
     * <RcvShow>...</RcvShow> XML state dump before returning the active handle.
     */
    private function openHandshakeSocket(RodeCasterDevice $device, ?string &$dump = ''): mixed
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->Port ?: 10024);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Invalid IP address configured for '{$device->Title}'.");
        }

        $fp = @fsockopen($ip, $port, $errno, $errstr, 0.75);
        if (!is_resource($fp)) {
            throw new RuntimeException("Unable to connect to RØDECaster at {$ip}:{$port} ({$errstr}).");
        }

        stream_set_timeout($fp, 0, 250_000);
        $dump = '';
        $deadline = microtime(true) + 2.5;
        while (microtime(true) < $deadline) {
            $chunk = @fread($fp, 16384);
            if ($chunk !== false && $chunk !== '') {
                $dump .= $chunk;
                if (str_contains($dump, '</RcvShow>')) {
                    usleep(80_000);
                    $tail = @fread($fp, 16384);
                    if ($tail !== false) {
                        $dump .= $tail;
                    }
                    break;
                }
            }
        }

        return $fp;
    }

    public function buildTcpOscIntPacket(string $address, int $value): string
    {
        $oscPayload = $this->padOscString($address) . $this->padOscString(',i') . pack('N', $value);
        return pack('V', strlen($oscPayload)) . $oscPayload;
    }

    public function buildTcpOscStringPacket(string $address, string $value): string
    {
        $oscPayload = $this->padOscString($address) . $this->padOscString(',s') . $this->padOscString($value);
        return pack('V', strlen($oscPayload)) . $oscPayload;
    }

    private function padOscString(string $str): string
    {
        $str .= "\0";
        $pad = (4 - (strlen($str) % 4)) % 4;
        return $str . str_repeat("\0", $pad);
    }

    public function isDeviceOnline(RodeCasterDevice $device, float $timeoutSeconds = 0.2): bool
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->Port ?: 10024);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeoutSeconds);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
    }
}
