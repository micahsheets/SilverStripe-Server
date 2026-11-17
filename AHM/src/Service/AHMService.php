<?php

namespace App\AHM\Service;

use App\AHM\Model\AHMDevice;
use RuntimeException;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;

class AHMService
{
    use Extensible;
    use Injectable;

    public function fetchFullCatalog(AHMDevice $device, bool $includeAllCrosspoints = false): array
    {
        $max = $device->getMaxChannels();
        $baseCh = $this->getBaseChannel($device);

        $queryBytes = '';
        for ($i = 1; $i <= $max; $i++) {
            $idx = $i - 1;
            $queryBytes .= $this->buildLevelGet($baseCh + 0, $idx) . $this->buildMuteGet($baseCh + 0, $idx) . $this->buildNameGet($baseCh, 0, $idx);
            $queryBytes .= $this->buildLevelGet($baseCh + 1, $idx) . $this->buildMuteGet($baseCh + 1, $idx) . $this->buildNameGet($baseCh, 1, $idx);
        }
        for ($g = 1; $g <= 32; $g++) {
            $idx = $g - 1;
            $queryBytes .= $this->buildLevelGet($baseCh + 2, $idx) . $this->buildMuteGet($baseCh + 2, $idx) . $this->buildNameGet($baseCh, 2, $idx);
        }

        $raw = $this->transact($device, $queryBytes, 450);
        [$levels, $mutes, $names] = $this->parseMidiAndSysExReplies($raw, $baseCh);

        $xpointLevels = [];
        $xpointAssigns = [];
        $chunkBytes = '';
        $count = 0;
        for ($in = 1; $in <= $max; $in++) {
            for ($z = 1; $z <= $max; $z++) {
                $chunkBytes .= $this->buildCrosspointGet($baseCh, $in - 1, $z - 1);
                if (++$count >= 128) {
                    $this->parseCrosspointReplies($this->transact($device, $chunkBytes, 180), $xpointLevels, $xpointAssigns);
                    $chunkBytes = '';
                    $count = 0;
                }
            }
        }
        if ($chunkBytes !== '') {
            $this->parseCrosspointReplies($this->transact($device, $chunkBytes, 180), $xpointLevels, $xpointAssigns);
        }

        $buildList = static function (int $limit, int $offset, string $prefix) use ($levels, $mutes, $names): array {
            $out = [];
            for ($n = 1; $n <= $limit; $n++) {
                $key = "{$offset}:" . ($n - 1);
                $midiVal = $levels[$key] ?? 107;
                $out[] = [
                    'num' => $n, 'label' => $names[$key] ?? "{$prefix} {$n}",
                    'percent' => self::midiToPercent($midiVal), 'db' => round(self::midiToDecibels($midiVal), 1),
                    'muted' => $mutes[$key] ?? false,
                ];
            }
            return $out;
        };

        $sends = [];
        for ($in = 1; $in <= $max; $in++) {
            $inLabel = $names['0:' . ($in - 1)] ?? "In {$in}";
            for ($z = 1; $z <= $max; $z++) {
                $pairKey = ($in - 1) . ':' . ($z - 1);
                $assigned = $xpointAssigns[$pairKey] ?? false;
                $midiVal = $xpointLevels[$pairKey] ?? 0;
                if (!$includeAllCrosspoints && !$assigned && $midiVal === 0) continue;
                $zoneLabel = $names['1:' . ($z - 1)] ?? "Zone {$z}";
                $sends[] = [
                    'key' => "{$in}>{$z}", 'input' => $in, 'zone' => $z, 'label' => "{$inLabel} -> {$zoneLabel}",
                    'assigned' => $assigned, 'percent' => self::midiToPercent($midiVal), 'db' => round(self::midiToDecibels($midiVal), 1),
                ];
            }
        }

        return [
            'fetchedAt' => date('Y-m-d H:i:s'),
            'model' => (string) ($device->DeviceModel ?: 'AHM-16'),
            'zones' => $buildList($max, 1, 'Zone'),
            'inputs' => $buildList($max, 0, 'Input'),
            'groups' => $buildList(32, 2, 'Group'),
            'sends' => $sends,
        ];
    }

    public function getDeviceState(AHMDevice $device, bool $fetchNames = false): array
    {
        $max = $device->getMaxChannels();
        $zones = $device->parseNumberedList((string) $device->ZonesConfig, $max);
        $inputs = $device->parseNumberedList((string) $device->InputsConfig, $max);
        $groups = $device->parseNumberedList((string) $device->GroupsConfig, 32);
        $sends = $device->parseSendsList((string) $device->SendsConfig, $max);
        $presets = $device->parseNumberedList((string) $device->PresetsConfig, 500);

        $baseCh = $this->getBaseChannel($device);
        $queryBytes = '';
        foreach ([1 => $zones, 0 => $inputs, 2 => $groups] as $offset => $list) {
            foreach ($list as $item) {
                $chByte = $item['num'] - 1;
                $queryBytes .= $this->buildLevelGet($baseCh + $offset, $chByte) . $this->buildMuteGet($baseCh + $offset, $chByte);
                if ($fetchNames) $queryBytes .= $this->buildNameGet($baseCh, $offset, $chByte);
            }
        }
        foreach ($sends as $s) {
            $queryBytes .= $this->buildCrosspointGet($baseCh, $s['input'] - 1, $s['zone'] - 1);
            if ($fetchNames) {
                $queryBytes .= $this->buildNameGet($baseCh, 0, $s['input'] - 1) . $this->buildNameGet($baseCh, 1, $s['zone'] - 1);
            }
        }

        $online = false;
        $parsedLevels = $parsedMutes = $parsedNames = $xpointLevels = $xpointAssigns = [];
        try {
            $raw = $this->transact($device, $queryBytes, $fetchNames ? 320 : 220);
            $online = true;
            [$parsedLevels, $parsedMutes, $parsedNames] = $this->parseMidiAndSysExReplies($raw, $baseCh);
            $this->parseCrosspointReplies($raw, $xpointLevels, $xpointAssigns);
        } catch (RuntimeException $e) {
            $online = false;
        }

        $hydrate = static function (array $items, int $offset, string $prefix) use ($parsedLevels, $parsedMutes, $parsedNames): array {
            $out = [];
            foreach ($items as $item) {
                $num = $item['num'];
                $key = "{$offset}:" . ($num - 1);
                $midiVal = $parsedLevels[$key] ?? 107;
                $cfgLabel = trim((string) $item['label']);
                $isGeneric = ($cfgLabel === '' || $cfgLabel === "Ch {$num}" || $cfgLabel === "{$prefix} {$num}");
                $out[] = [
                    'num' => $num,
                    'label' => ($isGeneric && !empty($parsedNames[$key])) ? $parsedNames[$key] : $cfgLabel,
                    'percent' => self::midiToPercent($midiVal),
                    'db' => round(self::midiToDecibels($midiVal), 1),
                    'muted' => $parsedMutes[$key] ?? false,
                ];
            }
            return $out;
        };

        $sendItems = [];
        foreach ($sends as $s) {
            $inIdx = $s['input'] - 1;
            $zIdx = $s['zone'] - 1;
            $pairKey = "{$inIdx}:{$zIdx}";
            $midiVal = $xpointLevels[$pairKey] ?? 107;
            $assigned = $xpointAssigns[$pairKey] ?? true;
            $cfgLabel = trim((string) $s['label']);
            $liveLabel = $cfgLabel;
            if (($cfgLabel === '' || $cfgLabel === "In {$s['input']} -> Zone {$s['zone']}") && (!empty($parsedNames["0:{$inIdx}"]) || !empty($parsedNames["1:{$zIdx}"]))) {
                $liveLabel = ($parsedNames["0:{$inIdx}"] ?? "In {$s['input']}") . ' -> ' . ($parsedNames["1:{$zIdx}"] ?? "Zone {$s['zone']}");
            }
            $sendItems[] = [
                'key' => $s['key'], 'input' => $s['input'], 'zone' => $s['zone'], 'label' => $liveLabel,
                'percent' => self::midiToPercent($midiVal), 'db' => round(self::midiToDecibels($midiVal), 1), 'muted' => !$assigned,
            ];
        }

        return [
            'online' => $online, 'syncedAt' => date('H:i:s'), 'model' => (string) ($device->DeviceModel ?: 'AHM-16'),
            'zones' => $hydrate($zones, 1, 'Zone'), 'inputs' => $hydrate($inputs, 0, 'Input'),
            'groups' => $hydrate($groups, 2, 'Group'), 'sends' => $sendItems, 'presets' => $presets,
        ];
    }

    public function setLevel(AHMDevice $device, string $targetType, int $number, float $percent): float
    {
        $midiCh = ($this->getBaseChannel($device) + $this->typeToChannelOffset($targetType)) & 0x0F;
        $chByte = max(0, min(127, $number - 1));
        $midiVal = self::percentToMidi($percent);
        $this->transact($device, pack('CCCCCCCCC', 0xB0 | $midiCh, 0x63, $chByte, 0xB0 | $midiCh, 0x62, 0x17, 0xB0 | $midiCh, 0x06, $midiVal), 60);
        return round(self::midiToDecibels($midiVal), 1);
    }

    public function setMute(AHMDevice $device, string $targetType, int $number, bool $muted): void
    {
        $midiCh = ($this->getBaseChannel($device) + $this->typeToChannelOffset($targetType)) & 0x0F;
        $chByte = max(0, min(127, $number - 1));
        $this->transact($device, pack('CCCCCC', 0x90 | $midiCh, $chByte, $muted ? 0x7F : 0x3F, 0x90 | $midiCh, $chByte, 0x00), 60);
    }

    public function setCrosspointSend(AHMDevice $device, int $inputNum, int $zoneNum, float $percent): float
    {
        $midiVal = self::percentToMidi($percent);
        $this->transact($device, pack('C*', 0xF0, 0x00, 0x00, 0x1A, 0x50, 0x12, 0x01, 0x00, $this->getBaseChannel($device) & 0x0F, 0x0E, max(0, min(63, $inputNum - 1)), max(0, min(63, $zoneNum - 1)), $midiVal, 0xF7), 60);
        return round(self::midiToDecibels($midiVal), 1);
    }

    public function setCrosspointMute(AHMDevice $device, int $inputNum, int $zoneNum, bool $muted): void
    {
        $this->transact($device, pack('C*', 0xF0, 0x00, 0x00, 0x1A, 0x50, 0x12, 0x01, 0x00, $this->getBaseChannel($device) & 0x0F, 0x0F, max(0, min(63, $inputNum - 1)), max(0, min(63, $zoneNum - 1)), $muted ? 0x00 : 0x01, 0xF7), 60);
    }

    public function recallPreset(AHMDevice $device, int $presetNumber): void
    {
        $zeroIdx = max(1, min(500, $presetNumber)) - 1;
        $baseCh = $this->getBaseChannel($device) & 0x0F;
        $this->transact($device, pack('CCCCC', 0xB0 | $baseCh, 0x00, (int) floor($zeroIdx / 128), 0xC0 | $baseCh, $zeroIdx % 128), 80);
    }

    public static function percentToMidi(float $percent): int { return (int) round((max(0.0, min(100.0, $percent)) / 100.0) * 127.0); }
    public static function midiToPercent(int $midi): int { return (int) round((max(0, min(127, $midi)) / 127.0) * 100.0); }
    public static function midiToDecibels(int $midi): float { return $midi <= 0 ? -90.0 : ($midi >= 107 ? (($midi - 107) / 20.0) * 10.0 : (($midi - 107) / 107.0) * 70.0); }

    private function buildLevelGet(int $midiCh, int $chByte): string { $c = $midiCh & 0x0F; return pack('CCCCCCCCC', 0xB0 | $c, 0x63, $chByte & 0x7F, 0xB0 | $c, 0x62, 0x17, 0xB0 | $c, 0x60, 0x7F); }
    private function buildMuteGet(int $midiCh, int $chByte): string { $c = $midiCh & 0x0F; return pack('CCCCCCCCC', 0xB0 | $c, 0x63, $chByte & 0x7F, 0xB0 | $c, 0x62, 0x09, 0xB0 | $c, 0x60, 0x7F); }
    private function buildNameGet(int $baseCh, int $offset, int $chIdx): string { return pack('C*', 0xF0, 0x00, 0x00, 0x1A, 0x50, 0x12, 0x01, 0x00, ($baseCh + $offset) & 0x0F, 0x09, $chIdx & 0x7F, 0xF7); }
    private function buildCrosspointGet(int $baseCh, int $inIdx, int $zoneIdx): string { return pack('C*', 0xF0, 0x00, 0x00, 0x1A, 0x50, 0x12, 0x01, 0x00, $baseCh & 0x0F, 0x0D, $inIdx & 0x3F, $zoneIdx & 0x3F, 0xF7); }

    private function parseMidiAndSysExReplies(string $raw, int $baseCh): array
    {
        $levels = $mutes = $names = $nrpnMsb = $nrpnLsb = [];
        $bytes = array_values(unpack('C*', $raw) ?: []);
        $len = count($bytes);
        for ($i = 0; $i < $len; $i++) {
            $s = $bytes[$i];
            if ($s === 0xF0) {
                $end = $i + 1;
                while ($end < $len && $bytes[$end] !== 0xF7) $end++;
                if (($end - $i) > 11 && $bytes[$i + 1] === 0x00 && $bytes[$i + 2] === 0x00 && $bytes[$i + 3] === 0x1A && $bytes[$i + 4] === 0x50 && $bytes[$i + 9] === 0x0A) {
                    $offset = (($bytes[$i + 8] & 0x0F) - $baseCh + 16) % 16;
                    $chars = '';
                    for ($k = $i + 11; $k < $end; $k++) if ($bytes[$k] >= 32 && $bytes[$k] <= 126) $chars .= chr($bytes[$k]);
                    if (trim($chars) !== '') $names["{$offset}:{$bytes[$i + 10]}"] = trim($chars);
                }
                $i = $end;
            } elseif (($s & 0xF0) === 0xB0 && ($i + 2) < $len) {
                $offset = (($s & 0x0F) - $baseCh + 16) % 16;
                $cc = $bytes[$i + 1]; $val = $bytes[$i + 2];
                if ($cc === 0x63) $nrpnMsb[$offset] = $val;
                elseif ($cc === 0x62) $nrpnLsb[$offset] = $val;
                elseif ($cc === 0x06 && isset($nrpnMsb[$offset])) {
                    $param = $nrpnLsb[$offset] ?? 0x17;
                    if ($param === 0x17) $levels["{$offset}:{$nrpnMsb[$offset]}"] = $val;
                    elseif ($param === 0x09) $mutes["{$offset}:{$nrpnMsb[$offset]}"] = ($val >= 0x40);
                }
                $i += 2;
            } elseif (($s & 0xF0) === 0x90 && ($i + 2) < $len) {
                $offset = (($s & 0x0F) - $baseCh + 16) % 16;
                $vel = $bytes[$i + 2];
                if ($vel >= 0x40) $mutes["{$offset}:{$bytes[$i + 1]}"] = true;
                elseif ($vel >= 0x01) $mutes["{$offset}:{$bytes[$i + 1]}"] = false;
                $i += 2;
            }
        }
        return [$levels, $mutes, $names];
    }

    private function parseCrosspointReplies(string $raw, array &$levels, array &$assigns): void
    {
        $bytes = array_values(unpack('C*', $raw) ?: []);
        $len = count($bytes);
        for ($i = 0; $i < $len; $i++) {
            if ($bytes[$i] === 0xF0) {
                $end = $i + 1;
                while ($end < $len && $bytes[$end] !== 0xF7) $end++;
                if (($end - $i) >= 13 && $bytes[$i + 1] === 0x00 && $bytes[$i + 2] === 0x00 && $bytes[$i + 3] === 0x1A && $bytes[$i + 4] === 0x50) {
                    $cmd = $bytes[$i + 9]; $pair = "{$bytes[$i + 10]}:{$bytes[$i + 11]}";
                    if ($cmd === 0x0E) $levels[$pair] = $bytes[$i + 12];
                    elseif ($cmd === 0x0F) $assigns[$pair] = ($bytes[$i + 12] === 0x01);
                }
                $i = $end;
            }
        }
    }

    private function typeToChannelOffset(string $type): int
    {
        return match (strtolower(trim($type))) { 'input', 'inputs' => 0, 'zone', 'zones' => 1, 'group', 'groups', 'dca' => 2, default => throw new RuntimeException("Unsupported AHM target: {$type}") };
    }

    private function getBaseChannel(AHMDevice $device): int { return max(0, min(15, ((int) ($device->MidiChannel ?: 1)) - 1)); }

    private function transact(AHMDevice $device, string $payload, int $readWindowMs = 120): string
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->Port ?: 51325);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException("Invalid IP for '{$device->Title}'.");
        $fp = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, 0.75);
        if (!is_resource($fp)) throw new RuntimeException("Unable to connect to AHM at {$ip}:{$port} ({$errstr}).");
        stream_set_timeout($fp, 0, 120_000);
        if ($payload !== '' && @fwrite($fp, $payload) === false) { fclose($fp); throw new RuntimeException("Failed writing to AHM at {$ip}:{$port}."); }
        fflush($fp);
        $raw = '';
        $deadline = microtime(true) + ($readWindowMs / 1000);
        while (!feof($fp) && microtime(true) < $deadline) {
            $chunk = @fread($fp, 8192);
            if ($chunk !== false && $chunk !== '') $raw .= $chunk;
            else usleep(12_000);
        }
        fclose($fp);
        return $raw;
    }
}
