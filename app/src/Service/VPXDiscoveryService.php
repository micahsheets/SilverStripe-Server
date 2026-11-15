<?php

namespace App\Service;

use App\Model\VPXDevice;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class VPXDiscoveryService
{
    use Injectable;

    /**
     * Scan via DashAV-compatible UDP probes and non-blocking native HTTP/401 detection across a CIDR subnet.
     */
    public function scan(string $cidr = '', int $udpPort = 6970, int $timeoutMs = 1200): array
    {
        $cleanCidr = trim($cidr);
        if ($cleanCidr === '') {
            $cleanCidr = $this->inferDefaultSubnet();
        }

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

        $ports = array_values(array_unique([$udpPort, 6970, 5000]));
        $queries = ["get hostname\r", "GET HOSTNAME\r", "get info\r", "GET INFO\r", "get devinfo\r", "get status\r"];

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

        // Native PHP socket HTTP probe (no php-curl extension required)
        if (count($targets) <= 512) {
            foreach ($this->probeHttpResponders($targets) as $httpIP => $banner) {
                if (!isset($responses[$httpIP])) {
                    $responses[$httpIP] = $banner;
                }
            }
        }

        // Always query each discovered IP directly over TCP port 6970 for its live hardware hostname
        foreach (array_keys($responses) as $ip) {
            $detail = $this->queryDeviceDetails($ip, $udpPort);
            if ($detail !== '') {
                $responses[$ip] = $detail . "\n" . $responses[$ip];
            }
        }

        return $this->formatDiscoveredDevices($responses, $udpPort);
    }

    /**
     * Query a specific VPX IP over TCP port 6970 for its live hardware hostname.
     */
    private function queryDeviceDetails(string $ip, int $udpPort): string
    {
        $fp = @fsockopen($ip, $udpPort ?: 6970, $errno, $errstr, 0.5);
        if (!is_resource($fp)) {
            return '';
        }

        stream_set_timeout($fp, 0, 500_000);
        @fwrite($fp, "get hostname\r");

        // Read until a complete JSON line/object is received or stream times out
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

        $raw = trim($raw);
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json) && ($json['status'] ?? '') === 'SUCCESS' && isset($json['result'])) {
                $hostname = is_array($json['result'])
                    ? trim((string) ($json['result']['hostname'] ?? ''))
                    : trim((string) $json['result']);

                if ($hostname !== '' && strtoupper($hostname) !== 'NULL') {
                    return "hostname={$hostname}";
                }
            }
        }

        return '';
    }

    /**
     * Perform an HTTP Digest (MD5) GET against Aurora VPX web info endpoints using default or configured credentials.
     */
    private function fetchAuthenticatedHttpInfo(string $ip): string
    {
        $user = getenv('VPX_WEB_USER') ?: 'admin';
        $pass = getenv('VPX_WEB_PASS') ?: 'admin';

        foreach (['/cgi-bin/get_info.cgi', '/cgi-bin/status.cgi', '/info.json', '/'] as $uri) {
            $fp = @fsockopen($ip, 80, $errno, $errstr, 0.35);
            if (!is_resource($fp)) {
                continue;
            }
            stream_set_timeout($fp, 0, 400_000);
            fwrite($fp, "GET {$uri} HTTP/1.0\r\nHost: {$ip}\r\n\r\n");
            $challenge = fread($fp, 2048);
            fclose($fp);

            if (!$challenge || !preg_match('/WWW-Authenticate:\s*Digest\s+([^\r\n]+)/i', $challenge, $m)) {
                continue;
            }

            $params = [];
            preg_match_all('/(\w+)="([^"]+)"/', $m[1], $matches, PREG_SET_ORDER);
            foreach ($matches as $pair) {
                $params[$pair[1]] = $pair[2];
            }

            if (empty($params['realm']) || empty($params['nonce'])) {
                continue;
            }

            $nc = '00000001';
            $cnonce = bin2hex(random_bytes(8));
            $qop = $params['qop'] ?? 'auth';
            $ha1 = md5("{$user}:{$params['realm']}:{$pass}");
            $ha2 = md5("GET:{$uri}");
            $responseHash = md5("{$ha1}:{$params['nonce']}:{$nc}:{$cnonce}:{$qop}:{$ha2}");

            $authHeader = sprintf(
                'Digest username="%s", realm="%s", nonce="%s", uri="%s", qop=%s, nc=%s, cnonce="%s", response="%s"',
                $user,
                $params['realm'],
                $params['nonce'],
                $uri,
                $qop,
                $nc,
                $cnonce,
                $responseHash
            );

            $fp2 = @fsockopen($ip, 80, $errno, $errstr, 0.35);
            if (!is_resource($fp2)) {
                continue;
            }
            stream_set_timeout($fp2, 0, 500_000);
            fwrite($fp2, "GET {$uri} HTTP/1.0\r\nHost: {$ip}\r\nAuthorization: {$authHeader}\r\n\r\n");
            $body = stream_get_contents($fp2, 8192);
            fclose($fp2);

            if ($body && preg_match('/^HTTP\/1\.[01]\s+200\b/i', $body)) {
                return $body;
            }
        }

        return '';
    }

    private function inferDefaultSubnet(): string
    {
        $envSubnet = getenv('VPX_DISCOVERY_SUBNET');
        if (is_string($envSubnet) && trim($envSubnet) !== '') {
            return trim($envSubnet);
        }

        $existingDevice = VPXDevice::get()->first();
        if ($existingDevice && filter_var($existingDevice->IPAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $existingDevice->IPAddress);
            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
        }

        return '192.168.1.0/24';
    }

    private function formatDiscoveredDevices(array $responses, int $udpPort): array
    {
        $existingByIP = [];
        foreach (VPXDevice::get() as $dev) {
            $existingByIP[$dev->IPAddress] = (int) $dev->ID;
        }

        $found = [];
        foreach ($responses as $ip => $rawText) {
            $hostname = $this->extractValue($rawText, ['hostname', 'host_name', 'dev_name', 'device_name', 'name'])
                ?: 'VPX-' . str_replace('.', '-', $ip);
            $mac = $this->extractMac($rawText);
            $mode = $this->detectMode($rawText, $hostname);

            $found[] = [
                'ip' => $ip,
                'udpPort' => $udpPort,
                'hostname' => $hostname,
                'title' => $hostname,
                'mode' => $mode,
                'mac' => $mac,
                'alreadyExists' => isset($existingByIP[$ip]),
                'existingId' => $existingByIP[$ip] ?? 0,
            ];
        }

        usort($found, fn(array $a, array $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
        return $found;
    }

    private function extractValue(string $text, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (preg_match('/["\']?' . preg_quote($key, '/') . '["\']?\s*[:=]\s*["\']?([^"\'\r\n,;\}]+)["\']?/i', $text, $m)) {
                $val = trim($m[1], " \t\n\r\0\x0B\"'");
                if ($val !== '' && stripos($val, '401') === false && stripos($val, 'Unauthorized') === false) {
                    return $val;
                }
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

    private function detectMode(string $text, string $hostname): string
    {
        $combined = strtolower($text . ' ' . $hostname);
        if (str_contains($combined, 'encoder') || str_contains($combined, 'transmitter') || preg_match('/\b(tx|enc)[-_0-9]/', $combined)) {
            return VPXDevice::MODE_ENCODER;
        }
        return VPXDevice::MODE_DECODER;
    }

    private function probeHttpResponders(array $ips): array
    {
        $validIps = array_values(array_filter(
            $ips,
            fn(string $ip) => $ip !== '255.255.255.255' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ));

        $auroraHosts = [];

        foreach (array_chunk($validIps, 32) as $batch) {
            $connecting = [];
            $connected = [];
            $buffers = [];

            foreach ($batch as $ip) {
                $sock = @stream_socket_client(
                    "tcp://{$ip}:80",
                    $errno,
                    $errstr,
                    0.3,
                    STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
                );
                if (is_resource($sock)) {
                    stream_set_blocking($sock, false);
                    $connecting[$ip] = $sock;
                }
            }

            if (empty($connecting)) {
                continue;
            }

            $connectDeadline = microtime(true) + 0.35;
            while (!empty($connecting) && microtime(true) < $connectDeadline) {
                $read = null;
                $write = $connecting;
                $except = null;
                $waitUs = (int) max(1000, ($connectDeadline - microtime(true)) * 1_000_000);

                if (@stream_select($read, $write, $except, 0, $waitUs) > 0) {
                    foreach ($write as $ip => $sock) {
                        if (@stream_socket_get_name($sock, true) !== false) {
                            @fwrite($sock, "GET / HTTP/1.0\r\nHost: {$ip}\r\n\r\n");
                            $connected[$ip] = $sock;
                            $buffers[$ip] = '';
                        } else {
                            @fclose($sock);
                        }
                        unset($connecting[$ip]);
                    }
                }
            }

            foreach ($connecting as $sock) {
                @fclose($sock);
            }

            $readDeadline = microtime(true) + 0.45;
            while (!empty($connected) && microtime(true) < $readDeadline) {
                $read = $connected;
                $write = null;
                $except = null;
                $waitUs = (int) max(1000, ($readDeadline - microtime(true)) * 1_000_000);

                if (@stream_select($read, $write, $except, 0, $waitUs) > 0) {
                    foreach ($read as $ip => $sock) {
                        $chunk = @fread($sock, 2048);
                        if ($chunk !== false && $chunk !== '') {
                            $buffers[$ip] .= $chunk;
                        }
                        if ($chunk === '' || $chunk === false || feof($sock) || strlen($buffers[$ip]) >= 2048) {
                            @fclose($sock);
                            unset($connected[$ip]);
                        }
                    }
                }
            }

            foreach ($connected as $sock) {
                @fclose($sock);
            }

            foreach ($buffers as $ip => $response) {
                if ($response === '') {
                    continue;
                }

                $isAurora = stripos($response, 'Aurora') !== false
                    || stripos($response, 'VPX') !== false
                    || stripos($response, 'IPBaseT') !== false
                    || (
                        preg_match('/^HTTP\/1\.[01]\s+401\b/i', $response)
                        && (stripos($response, 'realm="example"') !== false || stripos($response, 'lighttpd') !== false)
                    );

                if ($isAurora) {
                    $auroraHosts[$ip] = $response;
                }
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

        if ($bits === 32) {
            return [$baseIp];
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
