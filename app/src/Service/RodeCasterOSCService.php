<?php

namespace App\Service;

use App\Model\RodeCasterDevice;
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

    public function getDeviceState(RodeCasterDevice $device, float $timeoutSeconds = 0.4): array
    {
        $dump = '';
        $fp = $this->openHandshakeSocket($device, $dump, $timeoutSeconds);
        if (!is_resource($fp)) {
            return ['online' => false, 'switchingMode' => null, 'masterVolume' => null, 'padStates' => null];
        }
        fclose($fp);

        $mode = null;
        if (preg_match('/switching_mode="([^"]+)"/', $dump, $m)) {
            $mode = $m[1];
        }

        return [
            'online'        => true,
            'switchingMode' => $mode,
            'masterVolume'  => $this->parseProgramVolume($dump),
            'padStates'     => $this->parsePadStates($dump),
        ];
    }

    public function getShowLayout(RodeCasterDevice $device): array
    {
        $dump = '';
        $fp = $this->openHandshakeSocket($device, $dump, 0.6);
        if (is_resource($fp)) {
            fclose($fp);
        }

        // 1. Scenes (<Scene name="..."> inside <Scenes>)
        $scenes = [];
        if (preg_match('/<Scenes\b[^>]*>(.*?)<\/Scenes>/s', $dump, $sec)) {
            preg_match_all('/<Scene\b[^>]*\bname="([^"]*)"/i', $sec[1], $m);
            foreach ($m[1] as $idx => $name) {
                $slot = $idx + 1;
                $scenes[$slot] = trim($name) !== '' ? trim($name) : "Scene {$slot}";
            }
        }

        // 2. Inputs (<VideoInputs>)
        $inputs = [];
        if (preg_match('/<VideoInputs\b[^>]*>(.*?)<\/VideoInputs>/s', $dump, $sec)) {
            if (preg_match_all('/<value(\d+)>(.*?)<\/value\1>/s', $sec[1], $blocks, PREG_SET_ORDER)) {
                foreach ($blocks as $b) {
                    $slot = ((int) $b[1]) + 1;
                    $name = '';
                    if (preg_match('/<name>([^<]+)<\/name>/i', $b[2], $nm)) {
                        $name = trim($nm[1]);
                    } elseif (preg_match('/\bname="([^"]*)"/i', $b[2], $nm)) {
                        $name = trim($nm[1]);
                    }
                    if ($name !== '') {
                        $inputs[$slot] = $name;
                    }
                }
            }
            if (empty($inputs) && preg_match_all('/\bname="([^"]*)"/i', $sec[1], $m)) {
                foreach ($m[1] as $idx => $name) {
                    $slot = $idx + 1;
                    if (trim($name) !== '') {
                        $inputs[$slot] = trim($name);
                    }
                }
            }
        }
        for ($i = 1; $i <= 6; $i++) {
            if (empty($inputs[$i])) {
                if (preg_match('/\/videoIn\/' . $i . '\/name\x00+,s\x00+([^\x00]+)/s', $dump, $vn) && trim($vn[1]) !== '') {
                    $inputs[$i] = trim($vn[1]);
                } elseif (preg_match('/\/videoIn\/' . $i . '\/source\x00+,s\x00+([^\x00]+)/s', $dump, $vs) && trim($vs[1]) !== '' && trim($vs[1]) !== 'none') {
                    $inputs[$i] = strtoupper(trim($vs[1])) . " {$i}";
                } else {
                    $inputs[$i] = "Input {$i}";
                }
            }
        }

        // 3. Overlays (<value0>..<value6> inside <Overlays>)
        $overlays = [];
        if (preg_match('/<Overlays\b[^>]*>(.*?)<\/Overlays>/s', $dump, $sec)) {
            preg_match_all('/<value(\d+)>(.*?)<\/value\1>/s', $sec[1], $blocks, PREG_SET_ORDER);
            foreach ($blocks as $b) {
                $slot = ((int) $b[1]) + 1;
                $name = '';
                if (preg_match('/<name>([^<]+)<\/name>/i', $b[2], $nm)) {
                    $name = trim($nm[1]);
                }
                $overlays[$slot] = $name !== '' ? $name : "Overlay {$slot}";
            }
        }

        // 4. Media (<File name="..."> inside <MediaFiles>)
        $media = [];
        if (preg_match('/<MediaFiles\b[^>]*>(.*?)<\/MediaFiles>/s', $dump, $sec)) {
            preg_match_all('/<File\b[^>]*\bname="([^"]*)"/i', $sec[1], $m);
            foreach ($m[1] as $idx => $name) {
                $slot = $idx + 1;
                $media[$slot] = trim($name) !== '' ? trim($name) : "Media {$slot}";
            }
        }

        return [
            'scenes'   => $scenes,
            'inputs'   => $inputs,
            'overlays' => $overlays,
            'media'    => $media,
        ];
    }

    public function trigger(RodeCasterDevice $device, string $action, int $index = 0): array
    {
        $action = strtolower(trim($action));

        if ($action === 'volume') {
            return $this->setMasterVolume($device, $index);
        }

        if ($action === 'toggle_studio' || $action === 'instant' || $action === 'studio') {
            return $this->setSwitchingMode($device, $action);
        }

        if (isset(self::DIRECT_ACTION_MAP[$action])) {
            [$address, $value] = self::DIRECT_ACTION_MAP[$action];
            return $this->sendTcpOscPacket($device, $this->buildTcpOscIntPacket($address, $value));
        }

        if (!isset(self::ACTION_MAP[$action])) {
            return ['success' => false, 'error' => "Unsupported RØDECaster action '{$action}'."];
        }

        if ($index < 1 || $index > 64) {
            return ['success' => false, 'error' => "Invalid button index '{$index}'."];
        }

        $address = self::ACTION_MAP[$action];
        return $this->sendTcpOscPacket($device, $this->buildTcpOscIntPacket($address, $index));
    }

    public function setSwitchingMode(RodeCasterDevice $device, string $requested): array
    {
        $dump = '';
        $fp = $this->openHandshakeSocket($device, $dump, 0.75);
        if (!is_resource($fp)) {
            return ['success' => false, 'error' => 'Unable to connect to RØDECaster device'];
        }

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
            fflush($fp);

            if ($written === false || $written < strlen($packet)) {
                return ['success' => false, 'error' => 'Failed writing switching mode OSC packet'];
            }
        } finally {
            fclose($fp);
        }

        return ['success' => true, 'mode' => $targetMode];
    }

    public function setMasterVolume(RodeCasterDevice $device, int $percent): array
    {
        $clampedPercent = max(0, min(100, $percent));
        $floatLevel = round($clampedPercent / 100, 4);

        $dump = '';
        $fp = $this->openHandshakeSocket($device, $dump, 0.75);
        if (!is_resource($fp)) {
            return ['success' => false, 'error' => 'Unable to connect to RØDECaster device'];
        }

        try {
            $channels = $this->getActiveUnmutedChannels($dump);
            if (empty($channels)) {
                $channels = [1, 6, 7, 20, 21];
            }

            $payload = '';
            foreach ($channels as $oscCh) {
                $payload .= $this->buildTcpOscFloatPacket("/submix/main/{$oscCh}/level", $floatLevel);
                $payload .= $this->buildTcpOscFloatPacket("/submix/usb1/{$oscCh}/level", $floatLevel);
            }

            $written = @fwrite($fp, $payload);
            fflush($fp);

            if ($written === false || $written === 0) {
                return ['success' => false, 'error' => 'Failed writing audio fader OSC packets'];
            }
        } finally {
            fclose($fp);
        }

        return ['success' => true];
    }

    private function sendTcpOscPacket(RodeCasterDevice $device, string $packet): array
    {
        $dump = '';
        $fp = $this->openHandshakeSocket($device, $dump, 0.75);
        if (!is_resource($fp)) {
            return ['success' => false, 'error' => 'Unable to connect to RØDECaster device'];
        }

        try {
            $written = @fwrite($fp, $packet);
            fflush($fp);

            if ($written === false || $written < strlen($packet)) {
                return ['success' => false, 'error' => 'Failed writing OSC packet'];
            }
        } finally {
            fclose($fp);
        }

        return ['success' => true];
    }

    public function buildTcpOscIntPacket(string $address, int $value): string
    {
        $oscPayload = $this->padOscString($address) . $this->padOscString(',i') . pack('N', $value);
        return pack('V', strlen($oscPayload)) . $oscPayload;
    }

    public function buildTcpOscFloatPacket(string $address, float $value): string
    {
        $oscPayload = $this->padOscString($address) . $this->padOscString(',f') . pack('G', $value);
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

    private function openHandshakeSocket(RodeCasterDevice $device, ?string &$dump = '', float $timeoutSeconds = 0.75): mixed
    {
        $ip = trim((string) $device->IPAddress);
        $port = (int) ($device->Port ?: 10024);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeoutSeconds);
        if (!is_resource($fp)) {
            return null;
        }

        stream_set_timeout($fp, 0, 150_000);
        $dump = '';
        $deadline = microtime(true) + 2.0;
        $sawXmlEnd = false;

        while (microtime(true) < $deadline) {
            $chunk = @fread($fp, 32768);
            if ($chunk !== false && $chunk !== '') {
                $dump .= $chunk;
                if (!$sawXmlEnd && str_contains($dump, '</RcvShow>')) {
                    $sawXmlEnd = true;
                }
                if ($sawXmlEnd && (str_contains($dump, '/show/pgmcurrent') || str_contains($dump, '/device/buttons/'))) {
                    stream_set_blocking($fp, false);
                    usleep(45_000);
                    while (($tail = @fread($fp, 32768)) !== false && $tail !== '') {
                        $dump .= $tail;
                    }
                    stream_set_blocking($fp, true);
                    break;
                }
            } elseif ($sawXmlEnd) {
                break;
            } else {
                usleep(5_000);
            }
        }

        return $fp;
    }

    private function parseProgramVolume(string $dump): ?int
    {
        if (!preg_match('/<AudioMixer>.*?<\/AudioMixer>/s', $dump, $am)) {
            return null;
        }

        $xml = @simplexml_load_string($am[0]);
        if (!$xml || !isset($xml->audiosources)) {
            return null;
        }

        $levels = [];
        foreach ($xml->audiosources->children() as $src) {
            if ((string) $src->enabled === 'true' && (string) $src->mixes->value0->mute !== 'true') {
                $mainLvl = (float) $src->mixes->value0->level;
                $usbLvl  = (float) $src->mixes->value1->level;
                $levels[] = (($mainLvl + $usbLvl) / 2.0) * 100.0;
            }
        }

        if (empty($levels)) {
            return 0;
        }

        $avg = array_sum($levels) / count($levels);
        return max(0, min(100, (int) round($avg)));
    }

    private function getActiveUnmutedChannels(string $dump): array
    {
        if (!preg_match('/<AudioMixer>.*?<\/AudioMixer>/s', $dump, $am)) {
            return [];
        }

        $xml = @simplexml_load_string($am[0]);
        if (!$xml || !isset($xml->audiosources)) {
            return [];
        }

        $channels = [];
        foreach ($xml->audiosources->children() as $tag => $src) {
            if ((string) $src->enabled === 'true' && (string) $src->mixes->value0->mute !== 'true') {
                $zeroIdx = (int) str_replace('value', '', $tag);
                $channels[] = $zeroIdx + 1;
            }
        }

        return $channels;
    }

    private function parsePadStates(string $dump): array
    {
        $state = [
            'scenes'     => [],
            'inputs'     => [],
            'overlays'   => [],
            'media'      => [],
            'rawButtons' => [],
        ];

        $mapInputColour = static function (int $code): string {
            return match ($code) {
                4       => 'state-red',
                5       => 'state-green',
                3       => 'state-white',
                default => 'state-dim',
            };
        };

        preg_match_all('/\/device\/buttons\/(\d+)\/colour\x00+,i\x00\x00(.{4})/s', $dump, $matches, PREG_SET_ORDER);
        $buttons = [];
        foreach ($matches as $m) {
            $btn = (int) $m[1];
            $val = unpack('N', $m[2])[1] ?? 0;
            $buttons[$btn] = $val;
            $state['rawButtons'][$btn] = $val;
        }

        // 1. Scenes (1..7): Configured = White, Preview = Green, Program = Red
        if (preg_match('/<Scenes>(.*?)<\/Scenes>/s', $dump, $secMatch)) {
            preg_match_all('/<Scene\b[^>]*\bname="([^"]*)"/i', $secMatch[1], $sceneTags);
            foreach ($sceneTags[1] as $idx => $name) {
                $slot = $idx + 1;
                $state['scenes'][$slot] = trim($name) !== '' ? 'state-white' : 'state-dim';
            }
        }
        if (preg_match('/\bPvwScene="(-?\d+)"/', $dump, $m)) {
            $pvw = (int) $m[1];
            if ($pvw >= 0 && $pvw < 64) {
                $state['scenes'][$pvw + 1] = 'state-green';
            }
        }
        if (preg_match('/\bPgmScene="(-?\d+)"/', $dump, $m)) {
            $pgm = (int) $m[1];
            if ($pgm >= 0 && $pgm < 64) {
                $state['scenes'][$pgm + 1] = 'state-red';
            }
        }

        // 2. Inputs (1..6): Physical buttons 8..13
        for ($i = 1; $i <= 6; $i++) {
            $btnIdx = 7 + $i;
            if (isset($buttons[$btnIdx])) {
                $state['inputs'][$i] = $mapInputColour($buttons[$btnIdx]);
            }
        }

        // 3. Overlays (<value0>..<value6> inside <Overlays>): Configured = Blue/Teal, Preview = Green, Active = Red, Empty = Dim
        if (preg_match('/<Overlays\b[^>]*>(.*?)<\/Overlays>/s', $dump, $ovMatch)) {
            preg_match_all('/<value(\d+)>(.*?)<\/value\1>/s', $ovMatch[1], $valBlocks, PREG_SET_ORDER);
            foreach ($valBlocks as $block) {
                $slot = ((int) $block[1]) + 1;
                $inner = $block[2];
                $hasName = preg_match('/<name>([^<]+)<\/name>/i', $inner, $nm) && trim($nm[1]) !== '';
                $hasSource = preg_match('/<source>([^<]+)<\/source>/i', $inner, $sm) && trim($sm[1]) !== '' && trim($sm[1]) !== '|';

                $state['overlays'][$slot] = ($hasName || $hasSource) ? 'state-blue' : 'state-dim';
            }
        }
        if (preg_match('/\bPvwOverlay="(\d+)"/', $dump, $m)) {
            $ov = (int) $m[1];
            if ($ov >= 0 && $ov < 64 && ($state['overlays'][$ov + 1] ?? '') !== 'state-red') {
                $state['overlays'][$ov + 1] = 'state-green';
            }
        }
        if (preg_match('/\bPgmOverlay="(\d+)"/', $dump, $m)) {
            $ov = (int) $m[1];
            if ($ov >= 0 && $ov < 64) {
                $state['overlays'][$ov + 1] = 'state-red';
            }
        }

        // 4. Media (<File name="..."> inside <MediaFiles>): Configured = Pink, Preview = Green, Playing = Red, Empty = Dim
        if (preg_match('/<MediaFiles>(.*?)<\/MediaFiles>/s', $dump, $medMatch)) {
            preg_match_all('/<File\b[^>]*\bname="([^"]*)"/i', $medMatch[1], $fileTags);
            foreach ($fileTags[1] as $idx => $name) {
                $slot = $idx + 1;
                $state['media'][$slot] = trim($name) !== '' ? 'state-pink' : 'state-dim';
            }
        }

        if (preg_match_all('/\/show\/pvwcurrent\x00+,si\x00+([^\x00]+)\x00+(.{4})/s', $dump, $pvwMatches, PREG_SET_ORDER)) {
            $last = end($pvwMatches);
            if ($last[1] === 'media') {
                $medSlot = (unpack('N', $last[2])[1] ?? -1) + 1;
                if ($medSlot >= 1 && $medSlot <= 8 && ($state['media'][$medSlot] ?? '') !== 'state-red') {
                    $state['media'][$medSlot] = 'state-green';
                }
            }
        }

        if (preg_match_all('/\/show\/pgmcurrent\x00+,si\x00+([^\x00]+)\x00+(.{4})/s', $dump, $pgmMatches, PREG_SET_ORDER)) {
            $last = end($pgmMatches);
            if ($last[1] === 'media') {
                $medSlot = (unpack('N', $last[2])[1] ?? -1) + 1;
                if ($medSlot >= 1 && $medSlot <= 8) {
                    $state['media'][$medSlot] = 'state-red';
                }
            }
        }

        $isMediaBankActive = false;
        for ($i = 1; $i <= 7; $i++) {
            if (($buttons[$i] ?? 0) === 7) {
                $isMediaBankActive = true;
                break;
            }
        }
        if ($isMediaBankActive) {
            for ($i = 1; $i <= 7; $i++) {
                if (($buttons[$i] ?? 0) === 4) {
                    $state['media'][$i] = 'state-red';
                } elseif (($buttons[$i] ?? 0) === 5 && ($state['media'][$i] ?? '') !== 'state-red') {
                    $state['media'][$i] = 'state-green';
                }
            }
        }

        return $state;
    }
}
