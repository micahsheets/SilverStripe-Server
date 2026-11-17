<?php

namespace App\IBT\Service;

use App\IBT\Model\IBTDevice;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class IBTDiscoveryService
{
    use Injectable;

    public function scan(string $cidr = '', int $controlPort = 6970, int $timeoutMs = 1200): array
    {
        $cleanCidr = trim($cidr) !== '' ? trim($cidr) : $this->inferDefaultSubnet();
        $targets = $this->expandCidr($cleanCidr);
        $targets[] = '255.255.255.255';
        $targets = array_values(array_unique($targets));

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Unable to create discovery UDP socket: ' . socket_strerror(socket_last_error()));
        }

        @socket_bind($socket, '0.0.0.0', 0);
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_nonblock($socket);

        $ports = array_values(array_unique([$controlPort, 6970, 5000]));
        $queries = ["get hostname\r", "GET HOSTNAME\r", "get info\r", "get devinfo\r", "get status\r"];

        foreach ($targets as $ip) {
            foreach ($ports as $port) {
                foreach ($queries as $q) {
                    @socket_sendto($socket, $q, strlen($q), 0, $ip, $port);
                }
            }
        }

        $responses = [];
        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            $read = [$socket];
            $write = null;
            $except = null;
            $remainingUs = (int) max(1000, ($deadline - microtime(true)) * 1_000_000);
            $ready = @socket_select($read, $write, $except, 0, $remainingUs);
            if ($ready === false || $ready === 0) continue;

            $buf = '';
            $fromIP = '';
            $fromPort = 0;
            while (@socket_recvfrom($socket, $buf, 4096, 0, $fromIP, $fromPort) !== false) {
                if ($fromIP !== '' && $fromIP !== '255.255.255.255') {
                    $responses[$fromIP] = ($responses[$fromIP] ?? '') . "\n" . trim($buf);
                }
            }
        }
        socket_close($socket);

        foreach (array_keys($responses) as $ip) {
            $detail = $this->queryDeviceDetails($ip, $controlPort);
            if ($detail !== '') {
                $responses[$ip] = $detail . "\n" . $responses[$ip];
            }
        }

        return $this->formatDiscoveredDevices($responses, $controlPort);
    }

    private function queryDeviceDetails(string $ip, int $controlPort): string
    {
        $fp = @fsockopen($ip, $controlPort ?: 6970, $errno, $errstr, 0.45);
        if (!is_resource($fp)) return '';
        stream_set_timeout($fp, 0, 450_000);
        @fwrite($fp, "get hostname\r");

        $raw = '';
        $deadline = microtime(true) + 0.45;
        while (!feof($fp) && microtime(true) < $deadline) {
            $chunk = @fread($fp, 1024);
            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;
                if (str_contains($raw, '}')) break;
            } else {
                usleep(10_000);
            }
        }
        fclose($fp);

        $json = json_decode(trim($raw), true);
        if (is_array($json) && ($json['status'] ?? '') === 'SUCCESS' && isset($json['result'])) {
            $hostname = is_array($json['result'])
                ? trim((string) ($json['result']['hostname'] ?? ''))
                : trim((string) $json['result']);
            if ($hostname !== '' && strtoupper($hostname) !== 'NULL') {
                return "hostname={$hostname}";
            }
        }
        return '';
    }

    private function inferDefaultSubnet(): string
    {
        $envSubnet = getenv('PANEL_DISCOVERY_SUBNET');
        if (is_string($envSubnet) && trim($envSubnet) !== '') return trim($envSubnet);
        $existing = IBTDevice::get()->first();
        if ($existing && filter_var($existing->IPAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $existing->IPAddress);
            return "{$p[0]}.{$p[1]}.{$p[2]}.0/24";
        }
        return '192.168.1.0/24';
    }

    private function expandCidr(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$cidr] : [];
        }
        [$base, $prefixStr] = explode('/', $cidr, 2);
        $prefix = (int) $prefixStr;
        if (!filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 22 || $prefix > 32) {
            return [];
        }
        $baseLong = ip2long($base);
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        $network = $baseLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);
        $ips = [];
        for ($ip = $network + 1; $ip < $broadcast; $ip++) {
            $ips[] = long2ip($ip);
        }
        return $ips;
    }

    private function formatDiscoveredDevices(array $responses, int $controlPort): array
    {
        $existingByIP = [];
        foreach (IBTDevice::get() as $dev) {
            $existingByIP[$dev->IPAddress] = (int) $dev->ID;
        }

        $found = [];
        foreach ($responses as $ip => $rawText) {
            $hostname = preg_match('/hostname[=:]\s*([^\s\r\n"]+)/i', $rawText, $m)
                ? trim($m[1])
                : 'IBT-' . str_replace('.', '-', $ip);
            $mac = preg_match('/([0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){5})/', $rawText, $mm)
                ? strtoupper(str_replace('-', ':', $mm[1]))
                : '';
            $mode = preg_match('/\b(tx|encoder|transmitter)\b/i', $rawText . ' ' . $hostname)
                ? IBTDevice::MODE_ENCODER
                : IBTDevice::MODE_DECODER;

            $found[] = [
                'ip'            => $ip,
                'controlPort'   => $controlPort,
                'hostname'      => $hostname,
                'title'         => $hostname,
                'mode'          => $mode,
                'mac'           => $mac,
                'alreadyExists' => isset($existingByIP[$ip]),
                'existingId'    => $existingByIP[$ip] ?? 0,
            ];
        }

        usort($found, fn(array $a, array $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
        return $found;
    }
}
