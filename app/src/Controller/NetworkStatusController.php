<?php

namespace App\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\DB;
use Throwable;

class NetworkStatusController extends Controller
{
    private static string $url_segment = 'api/network-status';

    private static array $allowed_actions = [
        'index',
    ];

    public function index(HTTPRequest $request): HTTPResponse
    {
        $status = $this->checkLocalNetwork();

        return HTTPResponse::create(json_encode($status), 200)
            ->addHeader('Content-Type', 'application/json')
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function checkLocalNetwork(): array
    {
        $gatewayIp = $this->getDefaultGatewayIp();
        $containerIp = $this->getOutboundLocalIp($gatewayIp ?: '192.168.1.1');

        // 1. Check host-mounted /host/sys/class/net if mapped, otherwise /sys/class/net
        $netBase = is_dir('/host/sys/class/net') ? '/host/sys/class/net' : '/sys/class/net';
        $activeInterfaces = [];

        if (is_dir($netBase)) {
            foreach (scandir($netBase) as $iface) {
                if ($iface === '.' || $iface === '..' || $iface === 'lo') {
                    continue;
                }
                if (preg_match('/^(veth|br-|docker)/', $iface)) {
                    continue;
                }

                $operstateFile = "{$netBase}/{$iface}/operstate";
                $carrierFile   = "{$netBase}/{$iface}/carrier";
                $operstate     = is_file($operstateFile) ? trim((string) @file_get_contents($operstateFile)) : '';
                $carrier       = is_file($carrierFile) ? trim((string) @file_get_contents($carrierFile)) : '';

                if ($operstate === 'up' || $carrier === '1') {
                    $activeInterfaces[] = $iface;
                }
            }
        }

        // 2. Check configured AV hardware endpoints in DB (if any exist) to verify real LAN access beyond Docker
        $avCheck = $this->checkConfiguredAvDevices();

        $hasValidIp = ($containerIp !== null && !str_starts_with($containerIp, '127.') && !str_starts_with($containerIp, '169.254.'));
        $online = $hasValidIp && (!empty($activeInterfaces) || $gatewayIp !== null);

        if ($avCheck['lanTargets'] > 0 && $avCheck['reachable'] === 0) {
            $online = false;
        }

        $reasonParts = [];
        if ($online) {
            $ifaceLabel = !empty($activeInterfaces) ? $activeInterfaces[0] : 'lan';
            $reasonParts[] = "LAN Online ({$ifaceLabel}: {$containerIp})";
            if ($avCheck['total'] > 0) {
                $reasonParts[] = "{$avCheck['reachable']}/{$avCheck['total']} AV endpoints reachable";
            }
        } else {
            if (!$hasValidIp || empty($activeInterfaces)) {
                $reasonParts[] = 'No active LAN interface or IPv4 address';
            } else {
                $reasonParts[] = 'AV network unreachable (0/' . $avCheck['lanTargets'] . ' LAN devices responding)';
            }
        }

        return [
            'online'     => $online,
            'ip'         => $containerIp,
            'interfaces' => $activeInterfaces,
            'gateway'    => $gatewayIp,
            'avDevices'  => $avCheck,
            'reason'     => implode(' — ', $reasonParts),
        ];
    }

    private function getOutboundLocalIp(string $probeIp): ?string
    {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return null;
        }
        @socket_connect($sock, $probeIp, 53);
        $localIp = null;
        @socket_getsockname($sock, $localIp);
        @socket_close($sock);
        return is_string($localIp) && $localIp !== '0.0.0.0' ? $localIp : null;
    }

    private function getDefaultGatewayIp(): ?string
    {
        if (is_readable('/proc/net/route')) {
            $lines = file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $idx => $line) {
                if ($idx === 0) {
                    continue;
                }
                $cols = preg_split('/\s+/', trim($line));
                if (isset($cols[1], $cols[2]) && $cols[1] === '00000000' && strlen($cols[2]) === 8) {
                    $bytes = str_split($cols[2], 2);
                    $ip = implode('.', array_map('hexdec', array_reverse($bytes)));
                    if ($ip !== '0.0.0.0') {
                        return $ip;
                    }
                }
            }
        }
        return null;
    }

    private function checkConfiguredAvDevices(): array
    {
        $targets = [];
        try {
            $tables = DB::table_list();
            foreach ($tables as $tableName) {
                $cols = DB::field_list($tableName);
                if (!isset($cols['IPAddress'])) {
                    continue;
                }
                $portCol = isset($cols['Port']) ? ', "Port"' : '';
                $rows = DB::query("SELECT \"IPAddress\"{$portCol} FROM \"{$tableName}\" WHERE \"IPAddress\" IS NOT NULL AND \"IPAddress\" != '' LIMIT 10");
                foreach ($rows as $row) {
                    $ip = trim((string) ($row['IPAddress'] ?? ''));
                    $port = (int) ($row['Port'] ?? 80);
                    if ($port <= 0) {
                        $port = 80;
                    }
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $targets[$ip . ':' . $port] = ['ip' => $ip, 'port' => $port];
                    }
                }
            }
        } catch (Throwable $e) {
            return ['total' => 0, 'lanTargets' => 0, 'reachable' => 0];
        }

        $total = count($targets);
        $lanTargets = 0;
        $reachable = 0;

        foreach (array_slice($targets, 0, 5) as $target) {
            $ip = $target['ip'];
            $port = $target['port'];
            if (!str_starts_with($ip, '127.')) {
                $lanTargets++;
            }
            $errno = 0;
            $errstr = '';
            $sock = @fsockopen($ip, $port, $errno, $errstr, 0.25);
            if (is_resource($sock)) {
                fclose($sock);
                $reachable++;
            } elseif ($errno === 111) {
                $reachable++;
            }
        }

        return [
            'total'      => $total,
            'lanTargets' => $lanTargets,
            'reachable'  => $reachable,
        ];
    }
}
