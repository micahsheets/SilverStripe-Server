<?php

namespace App\Service;

use App\Model\EnttecDevice;
use App\Model\RouteAuditLog;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class EnttecSPlayService
{
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
            $rawPlaylists = [];
        }
        if (!is_array($rawCues)) {
            $rawCues = [];
        }

        $cuesById = [];
        $cuesByUuid = [];
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

        $blackoutId = (int) $device->BlackoutPlaylistID;
        $merged = [];

        foreach ($rawPlaylists as $pl) {
            if (!is_array($pl) || !isset($pl['playlist_id'])) {
                continue;
            }

            $plId = (int) $pl['playlist_id'];
            $name = trim((string) ($pl['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $isBlackout = ($plId === $blackoutId) || (strcasecmp($name, 'Blackout') === 0);
            $isEmpty = str_starts_with($name, '__EMPTY__');

            $matchedCue = null;
            if (!empty($pl['timeline']) && is_array($pl['timeline'])) {
                foreach ($pl['timeline'] as $track) {
                    if (!empty($track['cues'][0])) {
                        $cueRef = $track['cues'][0];
                        $refUuid = (string) ($cueRef['cue_uuid'] ?? '');
                        $refId = (int) ($cueRef['cue_id'] ?? -1);
                        if ($refUuid !== '' && isset($cuesByUuid[$refUuid])) {
                            $matchedCue = $cuesByUuid[$refUuid];
                        } elseif (isset($cuesById[$refId])) {
                            $matchedCue = $cuesById[$refId];
                        }
                        break;
                    }
                }
            }
            if (!$matchedCue && isset($cuesById[$plId])) {
                $matchedCue = $cuesById[$plId];
            }

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
                'source' => (int) ($matchedCue['config']['source'] ?? 0),
                'universes' => $matchedCue['config']['universes'] ?? [0],
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
            RouteAuditLog::record('Command', "Lighting ({$device->Title}): Activated '{$displayName}'", json_encode($payload), true);
            return $res;
        } catch (RuntimeException $e) {
            RouteAuditLog::record('Command', "Lighting ({$device->Title}): Failed '{$displayName}'", json_encode($payload), false, $e->getMessage());
            throw $e;
        }
    }

    public function triggerBlackout(EnttecDevice $device): array
    {
        $blackoutId = (int) $device->BlackoutPlaylistID;
        try {
            return $this->playPlaylist($device, $blackoutId, null, 'Blackout (Slot ' . $blackoutId . ')');
        } catch (RuntimeException $e) {
            $res = $this->sendApiCommand($device, ['command' => 7]);
            RouteAuditLog::record('Command', "Lighting ({$device->Title}): Blackout Fallback (Stop All)", '{"command":7}', true);
            return $res;
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
        if ($device->isMini() && ($playlistId < 1 || $playlistId > 15)) {
            throw new RuntimeException('S-Play Mini only supports overwriting slots 1 through 15.');
        }

        $this->sendApiCommand($device, [
            'command' => 13,
            'cue_id' => $cueId,
            'cue_uuid' => $cueUuid,
            'universe_type' => $sourceType,
            'universes' => array_values(array_map('intval', $universes)),
            'followers' => new \stdClass(),
        ]);

        usleep(200000);

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
                    'universes' => array_values(array_map('intval', $universes)),
                    'followers' => new \stdClass(),
                    'pixel_order' => 'RGB',
                    'color' => ['r' => 0, 'g' => 0, 'b' => 0, 'w' => 0, 'd' => 0, 'n' => 0],
                ],
            ],
        ]);

        $this->writePlaylistSlot($device, $playlistId, $playlistUuid, $cueId, $cueUuid, $name, 0, 30000);
        RouteAuditLog::record('Command', "Lighting ({$device->Title}): Recorded Static '{$name}' (Slot {$playlistId})", "Static Cue #{$cueId}", true);
    }

    public function armDynamicRecording(
        EnttecDevice $device,
        int $cueId,
        int $cueUuid,
        int $sourceType,
        array $universes
    ): array {
        return $this->sendApiCommand($device, [
            'command' => 14,
            'cue_id' => $cueId,
            'cue_uuid' => $cueUuid,
            'live_preview' => false,
            'universe_type' => $sourceType,
            'universes' => array_values(array_map('intval', $universes)),
        ]);
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
        if ($device->isMini() && ($playlistId < 1 || $playlistId > 15)) {
            throw new RuntimeException('S-Play Mini only supports overwriting slots 1 through 15.');
        }

        $stopResponse = $this->sendApiCommand($device, ['command' => 15]);
        $frames = $stopResponse['frames'] ?? $stopResponse['cue']['frames'] ?? $stopResponse['data']['frames'] ?? [];

        usleep(200000);
        $safeDuration = max(1000, $durationMs);

        $this->sendApiCommand($device, [
            'command' => 16,
            'cue' => [
                'cue_id' => $cueId,
                'cue_uuid' => $cueUuid,
                'name' => $name,
                'type' => 1,
                'duration' => $safeDuration,
                'config' => [
                    'ch_start' => 0,
                    'ch_stop' => 511,
                    'source' => $sourceType,
                    'universes' => array_values(array_map('intval', $universes)),
                    'followers' => new \stdClass(),
                    'pixel_order' => 'RGB',
                    'color' => ['r' => 0, 'g' => 0, 'b' => 0, 'w' => 0, 'd' => 0, 'n' => 0],
                ],
                'frames' => $frames,
            ],
        ]);

        $this->writePlaylistSlot($device, $playlistId, $playlistUuid, $cueId, $cueUuid, $name, 1, $safeDuration);
        RouteAuditLog::record('Command', "Lighting ({$device->Title}): Recorded Dynamic '{$name}' (Slot {$playlistId})", "Dynamic Cue #{$cueId}", true);
    }

    public function deletePlaylist(EnttecDevice $device, int $playlistId, int $cueId): void
    {
        if ($device->isMini()) {
            throw new RuntimeException('Deleting playlists is disabled on S-Play Mini hardware.');
        }
        if ($playlistId === (int) $device->BlackoutPlaylistID) {
            throw new RuntimeException('Cannot delete the reserved Blackout playlist (Slot 0).');
        }

        $this->sendApiCommand($device, ['command' => 19, 'playlist_id' => $playlistId]);
        if ($cueId > 0) {
            try {
                $this->sendApiCommand($device, ['command' => 17, 'cue_id' => $cueId]);
            } catch (RuntimeException $e) {
            }
        }

        RouteAuditLog::record('Command', "Lighting ({$device->Title}): Deleted Playlist Slot #{$playlistId}", "Delete #{$playlistId}", true);
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
                'event' => ['events' => []],
                'trigger' => ['triggers' => []],
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
