<?php

namespace App\DTX\Service;

use SilverStripe\Core\Extensible;

use App\DTX\Model\DTXDevice;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class DTXDiscoveryService
{
    use Extensible;

    use Injectable;

    /**
     * Scan via UDP broadcast and/or unicast CIDR subnet (e.g., "192.168.1.0/24") for Aurora DTX DSPs.
     */
    public function scan(string $cidr = '', int $udpPort = 6970, int $timeoutMs = 900): array
    {
        $targets = [];

        if (trim($cidr) !== '') {
            $targets = $this->expandCidr($cidr);
        }

        // Always include global broadcast for native/host-network deployments
        $targets[] = '255.255.255.255';
        $targets = array_values(array_unique($targets));

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Unable to create discovery UDP socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_nonblock($socket);

        // Aurora discovery/status queries
        $queries = ["get info\r", "get status\r"];
        foreach ($targets as $ip) {
            foreach ($queries as $q) {
                @socket_sendto($socket, $q, strlen($q), 0, $ip, $udpPort);
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
            if ($ready === false || $ready === 0) {
                continue;
            }

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

        // Fallback HTTP check for DTX web interfaces on port 80
        if (count($targets) <= 256) {
            foreach ($this->probeHttpResponders($targets) as $httpIP => $banner) {
                if (!isset($responses[$httpIP])) {
                    $responses[$httpIP] = $banner;
                }
            }
        }

        return $this->formatDiscoveredDevices($responses);
    }

    private function formatDiscoveredDevices(array $responses): array
    {
        $existingByIP = [];
        foreach (DTXDevice::get() as $dev) {
            $existingByIP[$dev->IPAddress] = (int) $dev->ID;
        }

        $found = [];
        foreach ($responses as $ip => $rawText) {
            // Only include Aurora DTX audio processors (ignore VPX video units)
            if (!$this->isDtxDevice($rawText)) {
                continue;
            }

            $model = $this->detectModel($rawText);
            $hostname = $this->extractValue($rawText, ['hostname', 'host_name', 'name'])
                ?: $model . '-' . str_replace('.', '-', $ip);
            $mac = $this->extractMac($rawText);

            $found[] = [
                'ip' => $ip,
                'controlPort' => 5000,
                'hostname' => $hostname,
                'title' => $hostname,
                'model' => $model,
                'mac' => $mac,
                'alreadyExists' => isset($existingByIP[$ip]),
                'existingId' => $existingByIP[$ip] ?? 0,
            ];
        }

        usort($found, fn(array $a, array $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
        return $found;
    }

    private function isDtxDevice(string $text): bool
    {
        return (bool) preg_match('/\bDTX[-_ ]?(44|88|64|DSP)?\b/i', $text);
    }

    private function detectModel(string $text): string
    {
        if (preg_match('/DTX[-_ ]?88/i', $text)) {
            return 'DTX-88';
        }
        return 'DTX-44';
    }

    private function extractValue(string $text, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (preg_match('/' . preg_quote($key, '/') . '\s*[:=]\s*([^\r\n,;]+)/i', $text, $m)) {
                return trim($m[1], " \t\n\r\0\x0B\"'");
            }
        }
        return null;
    }

    private function extractMac(string $text): string
    {
        if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $text, $m)) {
            return strtoupper(str_replace('-', ':', $m[0]));
        }
        return '';
    }

    /**
     * Fast non-blocking HTTP check for Aurora DTX web titles on port 80.
     */
    private function probeHttpResponders(array $ips): array
    {
        $auroraHosts = [];
        $sockets = [];

        foreach ($ips as $ip) {
            if ($ip === '255.255.255.255' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $sock = @stream_socket_client("tcp://{$ip}:80", $errno, $errstr, 0.05, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
            if (is_resource($sock)) {
                stream_set_blocking($sock, false);
                $sockets[$ip] = $sock;
            }
        }

        if (empty($sockets)) {
            return [];
        }

        $write = $sockets;
        $read = null;
        $except = null;
        @stream_select($read, $write, $except, 0, 200_000);

        foreach ($write as $ip => $sock) {
            @fwrite($sock, "GET / HTTP/1.0\r\nHost: {$ip}\r\n\r\n");
        }

        usleep(180_000);

        foreach ($sockets as $ip => $sock) {
            $data = @fread($sock, 2048);
            fclose($sock);
            if ($data && (stripos($data, 'DTX') !== false || stripos($data, 'Aurora') !== false)) {
                $title = '';
                if (preg_match('/<title>(.*?)<\/title>/is', $data, $m)) {
                    $title = trim($m[1]);
                }
                $auroraHosts[$ip] = "name={$title}\n{$data}";
            }
        }

        return $auroraHosts;
    }

    private function expandCidr(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            if (!filter_var($cidr, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('Invalid IP address or CIDR range.');
            }
            return [$cidr];
        }

        [$baseIp, $maskBits] = explode('/', $cidr, 2);
        $bits = (int) $maskBits;
        if (!filter_var($baseIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 22 || $bits > 32) {
            throw new RuntimeException('Please enter a valid IPv4 CIDR between /22 and /32 (e.g., 192.168.1.0/24).');
        }

        $ipLong = ip2long($baseIp);
        $mask = -1 << (32 - $bits);
        $network = $ipLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        $ips = [];
        for ($current = $network + 1; $current < $broadcast; $current++) {
            $ips[] = long2ip($current);
        }
        return $ips;
    }
}
