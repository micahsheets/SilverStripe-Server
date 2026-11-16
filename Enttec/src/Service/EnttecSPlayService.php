<?php

namespace App\Enttec\Service;

use SilverStripe\Core\Extensible;

use App\Enttec\Model\EnttecDevice;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class EnttecSPlayService
{
    use Extensible;

    use Injectable;

    public function sendApiCommand(EnttecDevice $device, array $payload, int $timeoutSeconds = 4): array
    {
        $ip = trim((string) $device->IPAddress);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Invalid IP address configured for '{$device->Title}'.");
        }

        $url = "http://{$ip}/api";
        $jsonBody = json_encode($payload);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for Enttec S-Play.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonBody),
            ],
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException("S-Play connection failed ({$ip}): {$curlError}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("S-Play HTTP {$httpCode} from {$ip}");
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON response from S-Play ({$ip}).");
        }
        if (isset($decoded['result']) && $decoded['result'] === false) {
            throw new RuntimeException('S-Play rejected command #' . ($payload['command'] ?? '?'));
        }

        return $decoded;
    }

    public function getPlaylistsAndCues(EnttecDevice $device): array
    {
        $playlistRes = $this->sendApiCommand($device, ['command' => 8]);
        $cueRes = $this->sendApiCommand($device, ['command' => 29]);

        $rawPlaylists = $playlistRes['playlists'] ?? $playlistRes;
        $rawCues = $cueRes['cues'] ?? $cueRes;
        if (!is_array($rawPlaylists)) {
            $rawPlaylists = array();
        }
        if (!is_array($rawCues)) {
            $rawCues = array();
        }

        $cuesById = array();
        $cuesByUuid = array();
        foreach ($rawCues as $cue) {
            if (!is_array($cue) || !isset($cue['cue_id'])) {
                continue;
            }
            $cuesById[(int) $cue['cue_id']] = $cue;
            $uuid = $cue['cue_uuid'] ?? $cue['uuid'] ?? null;
            if ($uuid !== null) {
                $cuesByUuid[(string) $uuid] = $cue;
            }
        }

        $slotMetaMap = $device->getSlotMetadataMap();
        $merged = array();

        foreach ($rawPlaylists as $pl) {
            if (!is_array($pl) || !isset($pl['playlist_id'])) {
                continue;
            }

            $plId = (int) $pl['playlist_id'];
            $name = trim((string) ($pl['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $isBlackout = (strcasecmp($name, 'Blackout') === 0);
            $isEmpty = str_starts_with($name, '__EMPTY__');

            $matchedCue = null;
            if (!empty($pl['timeline']) && is_array($pl['timeline'])) {
                foreach ($pl['timeline'] as $track) {
                    if (!empty($track['cues']) && is_array($track['cues'])) {
                        $cueRef = reset($track['cues']);
                        if (is_array($cueRef)) {
                            $refUuid = (string) ($cueRef['cue_uuid'] ?? $cueRef['uuid'] ?? '');
                            $refId = (int) ($cueRef['cue_id'] ?? -1);
                            if ($refUuid !== '' && isset($cuesByUuid[$refUuid])) {
                                $matchedCue = $cuesByUuid[$refUuid];
                            } elseif ($refId >= 0 && isset($cuesById[$refId])) {
                                $matchedCue = $cuesById[$refId];
                            }
                        }
                        break;
                    }
                }
            }
            if (!$matchedCue && isset($cuesById[$plId])) {
                $matchedCue = $cuesById[$plId];
            }

            $cachedMeta = $slotMetaMap[(string) $plId] ?? null;
            $detectedSource = $cachedMeta['source']
                ?? $matchedCue['config']['source']
                ?? $matchedCue['universe_type']
                ?? 0;

            $detectedUniverses = $cachedMeta['universes']
                ?? $matchedCue['config']['universes']
                ?? array();

            $merged[] = [
                'playlist_id' => $plId,
                'playlist_uuid' => $pl['playlist_uuid'] ?? $pl['uuid'] ?? $plId,
                'name' => $name,
                'status' => (int) ($pl['status'] ?? 0),
                'isBlackout' => $isBlackout,
                'isEmpty' => $isEmpty,
                'cue_id' => (int) ($matchedCue['cue_id'] ?? $plId),
                'cue_uuid' => $matchedCue['cue_uuid'] ?? $matchedCue['uuid'] ?? ($pl['playlist_uuid'] ?? $plId),
                'cue_type' => (int) ($matchedCue['type'] ?? 0),
                'source' => (int) $detectedSource,
                'universes' => is_array($detectedUniverses) ? $detectedUniverses : array(),
            ];
        }

        usort($merged, fn(array $a, array $b) => $a['playlist_id'] <=> $b['playlist_id']);
        return $merged;
    }


    public function playPlaylist(EnttecDevice $device, int $playlistId, ?int $playlistUuid = null, string $label = ''): array
    {
        $payload = ['command' => 0, 'playlist_id' => $playlistId];
        if ($playlistUuid !== null && $playlistUuid > 0) {
            $payload['playlist_uuid'] = $playlistUuid;
        }

        $displayName = $label !== '' ? $label : "Slot #{$playlistId}";
        try {
            $res = $this->sendApiCommand($device, $payload);
            $auditArgs = ['Command', "Lighting ({$device->Title}): Activated '{$displayName}'", json_encode($payload), true];
        $this->extend('onPanelAudit', ...$auditArgs);
            return $res;
        } catch (RuntimeException $e) {
            $auditArgs = ['Command', "Lighting ({$device->Title}): Failed '{$displayName}'", json_encode($payload), false, $e->getMessage()];
        $this->extend('onPanelAudit', ...$auditArgs);
            throw $e;
        }
    }

    public function triggerBlackout(EnttecDevice $device): array
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->UDPPort ?: 5000);
        $payload = trim((string) ($device->BlackoutCommand ?: 'stop'));

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Invalid IP address configured for '{$device->Title}'.");
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Unable to create UDP socket: ' . socket_strerror(socket_last_error()));
        }

        try {
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 0]);
            $bytesSent = @socket_sendto($socket, $payload, strlen($payload), 0, $ip, $port);
            if ($bytesSent === false) {
                $err = socket_strerror(socket_last_error($socket));
                $auditArgs = ['Command', "Lighting ({$device->Title}): Blackout UDP Failed", $payload, false, $err];
        $this->extend('onPanelAudit', ...$auditArgs);
                throw new RuntimeException("Failed sending UDP '{$payload}' to {$ip}:{$port} - {$err}");
            }

            $auditArgs = ['Command', "Lighting ({$device->Title}): Blackout (UDP '{$payload}')", "{$ip}:{$port} -> {$payload}", true];
        $this->extend('onPanelAudit', ...$auditArgs);
            return ['result' => true, 'udpSent' => $payload];
        } finally {
            socket_close($socket);
        }
    }


     public function recordStaticScene(
        EnttecDevice $device,
        int $playlistId,
        int $playlistUuid,
        int $cueId,
        int $cueUuid,
        string $name,
        int $sourceType,
        array $universes
    ): void {
        if ($device->isMini() && ($playlistId < 0 || $playlistId > 15)) {
            throw new RuntimeException('S-Play Mini only supports slots 0 through 15.');
        }
        if (strcasecmp(trim($name), 'Blackout') === 0) {
            throw new RuntimeException('Cannot overwrite the reserved Blackout playlist.');
        }

        $activeUniverses = array_values(array_map('intval', $universes));

        $this->sendApiCommand($device, [
            'command' => 13,
            'cue_id' => $cueId,
            'cue_uuid' => $cueUuid,
            'universe_type' => $sourceType,
            'universes' => $activeUniverses,
            'followers' => new \stdClass(),
        ]);

        usleep(250000);

        $this->sendApiCommand($device, [
            'command' => 16,
            'cue' => [
                'cue_id' => $cueId,
                'cue_uuid' => $cueUuid,
                'name' => $name,
                'type' => 0,
                'config' => [
                    'source' => $sourceType,
                    'ch_start' => 0,
                    'ch_stop' => 511,
                    'universes' => $activeUniverses,
                    'followers' => new \stdClass(),
                    'pixel_order' => 'RGB',
                    'color' => ['r' => 0, 'g' => 0, 'b' => 0, 'w' => 0, 'd' => 0, 'n' => 0],
                ],
            ],
        ]);

        $this->writePlaylistSlot($device, $playlistId, $playlistUuid, $cueId, $cueUuid, $name, 0, 30000);
        $device->saveSlotMetadata($playlistId, $sourceType, $activeUniverses);
        $auditArgs = ['Command', "Lighting ({$device->Title}): Recorded Static '{$name}' (Slot {$playlistId})", "Static Cue #{$cueId}", true];
        $this->extend('onPanelAudit', ...$auditArgs);
    }

    public function stopAndSaveDynamicScene(
        EnttecDevice $device,
        int $playlistId,
        int $playlistUuid,
        int $cueId,
        int $cueUuid,
        string $name,
        int $sourceType,
        array $universes,
        int $durationMs
    ): void {
        if ($device->isMini() && ($playlistId < 0 || $playlistId > 15)) {
            throw new RuntimeException('S-Play Mini only supports slots 0 through 15.');
        }
        if (strcasecmp(trim($name), 'Blackout') === 0) {
            throw new RuntimeException('Cannot overwrite the reserved Blackout playlist.');
        }

        $activeUniverses = array_values(array_map('intval', $universes));

        $stopResponse = $this->sendApiCommand($device, ['command' => 15]);
        $frames = $stopResponse['frames']
            ?? $stopResponse['cue']['frames']
            ?? $stopResponse['data']['frames']
            ?? null;

        usleep(250000);
        $safeDuration = max(1000, $durationMs);

        $cuePayload = [
            'cue_id' => $cueId,
            'cue_uuid' => $cueUuid,
            'name' => $name,
            'type' => 1,
            'duration' => $safeDuration,
            'config' => [
                'ch_start' => 0,
                'ch_stop' => 511,
                'source' => $sourceType,
                'universes' => $activeUniverses,
                'followers' => new \stdClass(),
                'pixel_order' => 'RGB',
                'color' => ['r' => 0, 'g' => 0, 'b' => 0, 'w' => 0, 'd' => 0, 'n' => 0],
            ],
        ];

        if (is_array($frames) && count($frames) > 0) {
            $cuePayload['frames'] = array_values($frames);
        }

        $this->sendApiCommand($device, [
            'command' => 16,
            'cue' => $cuePayload,
        ]);

        $this->writePlaylistSlot($device, $playlistId, $playlistUuid, $cueId, $cueUuid, $name, 1, $safeDuration);
        $device->saveSlotMetadata($playlistId, $sourceType, $activeUniverses);
        $auditArgs = ['Command', "Lighting ({$device->Title}): Recorded Dynamic '{$name}' (Slot {$playlistId})", "Dynamic Cue #{$cueId}", true];
        $this->extend('onPanelAudit', ...$auditArgs);
    }

    public function deletePlaylist(EnttecDevice $device, int $playlistId, int $cueId): void
    {
        if ($device->isMini()) {
            throw new RuntimeException('Deleting playlists is disabled on S-Play Mini hardware.');
        }

        $this->sendApiCommand($device, ['command' => 19, 'playlist_id' => $playlistId]);
        if ($cueId > 0) {
            try {
                $this->sendApiCommand($device, ['command' => 17, 'cue_id' => $cueId]);
            } catch (RuntimeException $e) {
            }
        }

        $auditArgs = ['Command', "Lighting ({$device->Title}): Deleted Playlist Slot #{$playlistId}", "Delete #{$playlistId}", true];
        $this->extend('onPanelAudit', ...$auditArgs);
    }


    private function writePlaylistSlot(
        EnttecDevice $device,
        int $playlistId,
        int $playlistUuid,
        int $cueId,
        int $cueUuid,
        string $name,
        int $cueType,
        int $durationMs
    ): void {
        $this->sendApiCommand($device, [
            'command' => 18,
            'playlist' => [
                'name' => $name,
                'playlist_id' => $playlistId,
                'playlist_uuid' => $playlistUuid,
                'order' => 2,
                'duration' => $durationMs,
                'intensity' => 100,
                'loop' => 0,
                'priority' => 100,
                'group' => 0,
                'start_trigger' => ['trigger_id' => -1],
                'stop_trigger' => ['trigger_id' => -1],
                'fade_in' => 0,
                'fade_out' => 0,
                'event' => ['events' => array()],
                'trigger' => ['triggers' => array()],
                'timeline' => [
                    'track1' => [
                        'intensity' => 100,
                        'cues' => [
                            [
                                'cue_id' => $cueId,
                                'cue_uuid' => $cueUuid,
                                'name' => $name,
                                'type' => $cueType,
                                'start' => 0,
                                'duration' => $durationMs,
                                'fade' => ['in' => 0, 'out' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
