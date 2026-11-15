<?php

namespace App\Service;

use App\Model\RouteAuditLog;
use App\Model\RoutePreset;
use App\Model\VPXDevice;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class VPXUDPService
{
    use Injectable;

    public function sendCommand(VPXDevice $device, string $command, bool $logAsRawCommand = true): bool
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->UDPPort ?: 6970);
        $cleanCmd = rtrim($command, "\r\n");

        $binaryCmd = preg_replace_callback(
            '/\\\\x([0-9A-Fa-f]{2})/',
            fn(array $m) => chr((int) hexdec($m[1])),
            $cleanCmd
        );

        try {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new RuntimeException("Invalid IP address configured for '{$device->Title}'.");
            }

            $payload = $binaryCmd . "\r";

            $fp = @fsockopen($ip, $port, $errno, $errstr, 0.5);
            if (is_resource($fp)) {
                stream_set_timeout($fp, 0, 500_000);
                if (@fwrite($fp, $payload) === false) {
                    fclose($fp);
                    throw new RuntimeException("Failed writing command to {$ip}:{$port}.");
                }

                $raw = '';
                $deadline = microtime(true) + 0.5;
                while (!feof($fp) && microtime(true) < $deadline) {
                    $chunk = @fread($fp, 1024);
                    if ($chunk !== false && $chunk !== '') {
                        $raw .= $chunk;
                        if (str_contains($raw, '}')) {
                            break;
                        }
                    } else {
                        usleep(10_000);
                    }
                }
                fclose($fp);

                $json = json_decode(trim($raw), true);
                if (is_array($json) && ($json['status'] ?? '') === 'ERROR') {
                    throw new RuntimeException(
                        sprintf('VPX rejected "%s": %s', $cleanCmd, (string) ($json['error'] ?? 'UNKNOWN_ERROR'))
                    );
                }
            } else {
                $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                if ($socket === false) {
                    throw new RuntimeException('Unable to create UDP socket: ' . socket_strerror(socket_last_error()));
                }
                try {
                    socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);
                    if (@socket_sendto($socket, $payload, strlen($payload), 0, $ip, $port) === false) {
                        throw new RuntimeException(
                            sprintf('Failed sending UDP to %s:%d - %s', $ip, $port, socket_strerror(socket_last_error($socket)))
                        );
                    }
                } finally {
                    socket_close($socket);
                }
            }

            if ($logAsRawCommand) {
                RouteAuditLog::record('Command', "{$device->Title}: {$cleanCmd}", $cleanCmd, true, null, $device);
            }

            return true;
        } catch (RuntimeException $e) {
            if ($logAsRawCommand) {
                RouteAuditLog::record('Command', "{$device->Title}: {$cleanCmd}", $cleanCmd, false, $e->getMessage(), $device);
            }
            throw $e;
        }
    }

    public function route(VPXDevice $decoder, ?VPXDevice $encoder, string $streamType = 'all'): array
    {
        if ($decoder->Mode !== VPXDevice::MODE_DECODER) {
            throw new RuntimeException("Target device '{$decoder->Title}' is an Encoder, not a Decoder.");
        }

        $streamType = strtolower(trim($streamType));
        if (!in_array($streamType, ['all', 'video', 'audio', 'usb', 'ir'], true)) {
            throw new RuntimeException("Invalid stream type '{$streamType}'.");
        }

        if ($encoder !== null && $encoder->Mode !== VPXDevice::MODE_ENCODER) {
            throw new RuntimeException("Source device '{$encoder->Title}' is not an Encoder.");
        }

        $commands = $encoder !== null
            ? (array) $decoder->buildJoinCommand($encoder, $streamType)
            : (array) $decoder->buildLeaveCommand($streamType);

        $loggedCommands = implode(' ; ', array_map('trim', $commands));
        $sourceLabel = $encoder ? $encoder->Title : 'Blank / Unsubscribed';
        $summary = sprintf('%s -> %s (%s)', $sourceLabel, $decoder->Title, strtoupper($streamType));

        try {
            foreach ($commands as $cmd) {
                $this->sendCommand($decoder, $cmd, false);
            }

            $encoderID = $encoder ? (int) $encoder->ID : 0;
            if ($streamType === 'all' || $streamType === 'video') {
                $decoder->SubscribedVideoEncoderID = $encoderID;
            }
            if ($streamType === 'all' || $streamType === 'audio') {
                $decoder->SubscribedAudioEncoderID = $encoderID;
            }
            if ($streamType === 'all' || $streamType === 'usb') {
                $decoder->SubscribedUSBEncoderID = $encoderID;
            }
            $decoder->write();

            RouteAuditLog::record('Route', $summary, $loggedCommands, true, null, $decoder, $encoder);

            return [
                'decoder' => $decoder->Title,
                'decoderIP' => $decoder->IPAddress,
                'encoder' => $encoder ? $encoder->Title : null,
                'streamType' => $streamType,
                'commandSent' => $loggedCommands,
            ];
        } catch (RuntimeException $e) {
            RouteAuditLog::record('Route', $summary, $loggedCommands, false, $e->getMessage(), $decoder, $encoder);
            throw $e;
        }
    }

    public function executePreset(RoutePreset $preset): array
    {
        $results = [];
        $errors = [];

        foreach ($preset->Actions() as $action) {
            $decoder = $action->Decoder();
            if (!$decoder->exists()) {
                $errors[] = "Action #{$action->ID}: Target Decoder missing.";
                continue;
            }
            $encoder = $action->Encoder()->exists() ? $action->Encoder() : null;

            try {
                $results[] = $this->route($decoder, $encoder, (string) $action->StreamType);
            } catch (RuntimeException $e) {
                $errors[] = "{$decoder->Title}: " . $e->getMessage();
            }
        }

        $hasErrors = count($errors) > 0;
        RouteAuditLog::record(
            'Preset',
            "Triggered Preset: {$preset->Title}",
            count($results) . ' route(s) sent',
            !$hasErrors,
            $hasErrors ? implode('; ', $errors) : null,
            null,
            null,
            $preset
        );

        return [
            'preset' => $preset->Title,
            'executed' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Fast non-blocking TCP reachability check against the VPX unit's web/control port (default 80).
     */
    public function isDeviceOnline(VPXDevice $device, int $probePort = 80, float $timeoutSeconds = 0.15): bool
    {
        $ip = trim((string) $device->IPAddress);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $conn = @fsockopen($ip, $probePort, $errno, $errstr, $timeoutSeconds);
        if (is_resource($conn)) {
            fclose($conn);
            return true;
        }

        return false;
    }
}
