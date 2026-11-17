<?php

namespace App\Auctioneer\Service;

use App\Auctioneer\Model\AuctioneerProfile;
use App\Auctioneer\Model\AuctionLane;
use App\Auctioneer\Model\AuctionPanelStation;
use RuntimeException;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;

class AuctioneerDSPService
{
    use Extensible;
    use Injectable;

    private const MSG_SET_PARAM = 0x21;
    private const MSG_GET_PARAM = 0x22;

    /**
     * Builds an exact 12-byte Aurora DTX v1 binary packet matching dtx_pp.js:
     * [0xB3, msgType, checksum, 0x00, mid_L, mid_H, pt_L, pt_H, p1_L, p1_H, p2_L, p2_H]
     */
    public static function buildPacketV1(int $msgType, int $mid, int $pt, int $p1, int $p2): string
    {
        $bytes = [
            0xB3,
            $msgType & 0xFF,
            0x00,
            0x00,
            $mid & 0xFF,
            ($mid >> 8) & 0xFF,
            $pt & 0xFF,
            ($pt >> 8) & 0xFF,
            $p1 & 0xFF,
            ($p1 >> 8) & 0xFF,
            $p2 & 0xFF,
            ($p2 >> 8) & 0xFF,
        ];
        $bytes[2] = array_sum($bytes) & 0xFF;
        return pack('C12', ...$bytes);
    }

    /**
     * Pushes sensitivity, input gain, mute, 8-band PEQ, and compressor parameters
     * to the Aurora DTX unit using the exact binary v1 protocol from dtx_pp.js.
     */
    public function applySettingsToStation(AuctionPanelStation $station, array $settings): array
    {
        $lane = $station->Lane();
        if (!$lane || !$lane->exists()) {
            throw new RuntimeException("Touch panel '{$station->Title}' is not assigned to an Auction Lane.");
        }

        $dtx = $lane->DTXDevice();
        if (!$dtx || !$dtx->exists()) {
            throw new RuntimeException("Lane '{$lane->Title}' does not have an Aurora DTX unit assigned.");
        }

        $ch = max(0, (int) $station->DTXChannel - 1); // 0-based channel index on DTX wire
        $ip = trim((string) $dtx->IPAddress);
        $port = (int) ($dtx->ControlPort ?: 5000);

        $packets = [];

        // 1. Input Sensitivity (mid 0x012B, pt 0x0003, p1=ch, p2=0..16) & Input Gain (pt 0x0001, gain*100)
        $sens = max(0, min(16, (int) ($settings['sensitivity'] ?? 0)));
        $gainRaw = ((int) round(((float) ($settings['gain'] ?? 0.0)) * 100)) & 0xFFFF;
        $muted = !empty($settings['muted']) ? 1 : 0;

        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, 0x012B, 0x0003, $ch, $sens);
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, 0x012B, 0x0001, $ch, $gainRaw);
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, 0x012B, 0x0002, $ch, $muted);

        // 2. 8-Band Parametric EQ (mid = 0x0061 + ch)
        $peq = is_array($settings['peq'] ?? null) ? $settings['peq'] : [];
        $peqEn = !empty($peq['enabled']) ? 1 : 0;
        $peqMid = 0x0061 + $ch;
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $peqMid, 0x0001, $peqEn, $peqEn);

        $bands = is_array($peq['band'] ?? null) ? $peq['band'] : AuctioneerProfile::defaultPeqBands();
        for ($b = 0; $b < 8; $b++) {
            $band = $bands[$b] ?? ['enabled' => 1, 'type' => 6, 'freq' => 1000, 'gain' => 0.0, 'q' => 0.71];
            $bEn   = !empty($band['enabled']) ? 1 : 0;
            $bFreq = max(20, min(20000, (int) ($band['freq'] ?? 1000)));
            $bGain = ((int) round(((float) ($band['gain'] ?? 0.0)) * 100)) & 0xFFFF;
            $bQ    = ((int) round(((float) ($band['q'] ?? 0.71)) * 100)) & 0xFFFF;
            $bType = (int) ($band['type'] ?? 6);

            $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $peqMid, 0x0002, $b, $bEn);
            $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $peqMid, 0x0003, $b, $bFreq);
            $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $peqMid, 0x0004, $b, $bGain);
            $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $peqMid, 0x0005, $b, $bQ);
            $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $peqMid, 0x0006, $b, $bType);
        }

        // 3. Input Compressor (mid = 0x0021 + ch)
        $comp = is_array($settings['comp'] ?? null) ? $settings['comp'] : [];
        $compMid = 0x0021 + $ch;
        $cEn     = !empty($comp['enabled']) ? 1 : 0;
        $cThresh = ((int) round(((float) ($comp['threshold'] ?? -24.0)) * 100)) & 0xFFFF;
        $cRatio  = ((int) round(((float) ($comp['ratio'] ?? 3.0)) * 100)) & 0xFFFF;
        $cAttack = max(1, min(500, (int) ($comp['attack'] ?? 10)));
        $cRel    = max(1, min(10000, (int) ($comp['release'] ?? 500)));
        $cGain   = ((int) round(((float) ($comp['gain'] ?? 0.0)) * 100)) & 0xFFFF;

        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $compMid, 0x0001, $cEn, 0x0000);
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $compMid, 0x0002, $cThresh, 0x0000);
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $compMid, 0x0003, $cRatio, 0x0000);
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $compMid, 0x0004, $cAttack, 0x0000);
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $compMid, 0x0005, $cRel, 0x0000);
        $packets[] = self::buildPacketV1(self::MSG_SET_PARAM, $compMid, 0x0006, $cGain, 0x0000);

        $this->sendPacketsUDP($ip, $port, $packets);

        return [
            'dtxTitle' => $dtx->Title,
            'dtxIP'    => $ip,
            'channel'  => $ch + 1,
            'settings' => $settings,
        ];
    }

    /**
     * Queries live DSP channel state back from the DTX hardware (matching _dtxSaveParam)
     * and merges with the current UI settings if the hardware is offline.
     */
    public function readLiveSettingsFromStation(AuctionPanelStation $station, array $fallbackSettings): array
    {
        $lane = $station->Lane();
        $dtx = ($lane && $lane->exists()) ? $lane->DTXDevice() : null;
        if (!$dtx || !$dtx->exists() || !filter_var($dtx->IPAddress, FILTER_VALIDATE_IP)) {
            return $fallbackSettings;
        }

        $ip = trim((string) $dtx->IPAddress);
        $port = (int) ($dtx->ControlPort ?: 5000);
        $ch = max(0, (int) $station->DTXChannel - 1);

        $fp = @fsockopen("udp://{$ip}", $port, $errno, $errstr, 0.35);
        if (!$fp) {
            return $fallbackSettings;
        }
        stream_set_timeout($fp, 0, 80000);

        $result = $fallbackSettings;
        $sensResp = $this->queryParam($fp, 0x012B, 0x0003, $ch);
        if ($sensResp !== null) {
            $result['sensitivity'] = max(0, min(16, $sensResp['p2']));
        }
        $gainResp = $this->queryParam($fp, 0x012B, 0x0001, $ch);
        if ($gainResp !== null) {
            $signed = $gainResp['p2'] >= 32768 ? $gainResp['p2'] - 65536 : $gainResp['p2'];
            $result['gain'] = round($signed / 100.0, 2);
        }

        fclose($fp);
        return $result;
    }

    public function loginAuctioneer(AuctionPanelStation $station, AuctioneerProfile $profile): array
    {
        $settings = $profile->toDspSettingsArray();
        $applied = $this->applySettingsToStation($station, $settings);

        $station->ActiveAuctioneerID = (int) $profile->ID;
        $station->LastLoginAt = DBDatetime::now()->Rfc2822();
        $station->write();

        return $applied;
    }

    public function logoutStation(AuctionPanelStation $station): array
    {
        $lane = $station->Lane();
        $defaults = ($lane && $lane->exists())
            ? $lane->getDefaultDspSettingsArray()
            : AuctionLane::create()->getDefaultDspSettingsArray();

        $applied = $this->applySettingsToStation($station, $defaults);

        $station->ActiveAuctioneerID = 0;
        $station->write();

        return $applied;
    }

    private function queryParam($fp, int $mid, int $pt, int $p1): ?array
    {
        $pkt = self::buildPacketV1(self::MSG_GET_PARAM, $mid, $pt, $p1, 0x0000);
        if (@fwrite($fp, $pkt) === false) {
            return null;
        }
        $resp = @fread($fp, 16);
        if (!is_string($resp) || strlen($resp) < 12) {
            return null;
        }
        $u = @unpack('Csync/CmsgType/Cchk/Cver/vmid/vpt/vp1/vp2', $resp);
        return is_array($u) ? $u : null;
    }

    private function sendPacketsUDP(string $ip, int $port, array $packets): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }
        $fp = @fsockopen("udp://{$ip}", $port, $errno, $errstr, 0.5);
        if (!$fp) {
            return;
        }
        stream_set_timeout($fp, 0, 200000);
        foreach ($packets as $pkt) {
            @fwrite($fp, $pkt);
            usleep(4000); // 4ms pacing matching DTX_MSG_DELAY
        }
        fclose($fp);
    }
}
