<?php

namespace App\Service;

use App\Model\RoutePreset;
use App\Model\VPXDevice;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class VPXUDPService
{
    use Injectable;

    /**
     * Send a raw UDP ASCII command to a specific VPX device.
     *
     * @throws RuntimeException If socket creation or transmission fails
     */
    public function sendCommand(VPXDevice $device, string $command): bool
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->UDPPort ?: 6970);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Invalid IP address configured for device '{$device->Title}'.");
        }

        // Ensure command ends with standard carriage return expected by Aurora VPX
        $payload = rtrim($command, "\r\n") . "\r";

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Unable to create UDP socket: ' . socket_strerror(socket_last_error()));
        }

        try {
            // Set a short 1-second timeout so UI requests never hang
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);

            $bytesSent = @socket_sendto($socket, $payload, strlen($payload), 0, $ip, $port);
            if ($bytesSent === false) {
                throw new RuntimeException(
                    sprintf('Failed sending UDP packet to %s:%d - %s', $ip, $port, socket_strerror(socket_last_error($socket)))
                );
            }

            return true;
        } finally {
            socket_close($socket);
        }
    }

    /**
     * Route a Decoder to an Encoder (or unsubscribe if $encoder is null) and update database state.
     */
    public function route(VPXDevice $decoder, ?VPXDevice $encoder, string $streamType = 'all'): array
    {
        if ($decoder->Mode !== VPXDevice::MODE_DECODER) {
            throw new RuntimeException("Target device '{$decoder->Title}' is configured as an Encoder, not a Decoder.");
        }

        $streamType = strtolower(trim($streamType));
        $allowedStreams = ['all', 'video', 'audio', 'usb', 'ir'];
        if (!in_array($streamType, $allowedStreams, true)) {
            throw new RuntimeException("Invalid stream type '{$streamType}'.");
        }

        if ($encoder !== null) {
            if ($encoder->Mode !== VPXDevice::MODE_ENCODER) {
                throw new RuntimeException("Source device '{$encoder->Title}' is not an Encoder.");
            }
            $command = $decoder->buildJoinCommand($encoder, $streamType);
        } else {
            // Unsubscribe / leave stream
            $command = sprintf("leave %s\r", $streamType);
        }

        $this->sendCommand($decoder, $command);

        // Keep Silverstripe ORM state synchronized with hardware routing
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

        return [
            'decoder' => $decoder->Title,
            'decoderIP' => $decoder->IPAddress,
            'encoder' => $encoder ? $encoder->Title : null,
            'streamType' => $streamType,
            'commandSent' => trim($command),
        ];
    }

    /**
     * Execute every routing action inside a RoutePreset.
     */
    public function executePreset(RoutePreset $preset): array
    {
        $results = [];
        $errors = [];

        foreach ($preset->Actions() as $action) {
            $decoder = $action->Decoder();
            if (!$decoder->exists()) {
                $errors[] = "Action #{$action->ID}: Target Decoder no longer exists.";
                continue;
            }

            $encoder = $action->Encoder()->exists() ? $action->Encoder() : null;

            try {
                $results[] = $this->route($decoder, $encoder, (string) $action->StreamType);
            } catch (RuntimeException $e) {
                $errors[] = "{$decoder->Title}: " . $e->getMessage();
            }
        }

        return [
            'preset' => $preset->Title,
            'executed' => $results,
            'errors' => $errors,
        ];
    }
}
