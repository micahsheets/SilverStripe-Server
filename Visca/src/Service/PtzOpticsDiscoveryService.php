<?php

namespace App\Visca\Service;

use App\Visca\Model\ViscaCamera;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class PtzOpticsDiscoveryService
{
    use Injectable;

    /**
     * Cross-subnet discovery for PTZOptics Move SE / G2 / G3 cameras using:
     *  1. WS-Discovery / ONVIF Multicast (239.255.255.250:3702) - works across subnets on same L2 network
     *  2. mDNS Multicast (224.0.0.251:5353) - catches .local / NDI / HTTP advertisements across subnets
     *  3. Optional CIDR / IP HTTP CGI probe (e.g. 192.168.100.0/24 or 192.168.100.4)
     */
    public function scan(string $subnetOrIp = '', int $timeoutMs = 1300): array
    {
        $foundByIp = [];

        // 1. WS-Discovery ONVIF Probe (cross-subnet Layer-2 multicast)
        foreach ($this->probeWsDiscovery($timeoutMs) as $ip => $info) {
            $foundByIp[$ip] = array_merge($foundByIp[$ip] ?? [], $info);
        }

        // 2. mDNS Multicast Probe (cross-subnet Layer-2 multicast)
        foreach ($this->probeMdns((int) max(600, $timeoutMs * 0.7)) as $ip => $info) {
            $foundByIp[$ip] = array_merge($foundByIp[$ip] ?? [], $info);
        }

        // 3. Optional Unicast / CIDR HTTP CGI & VISCA check
        $targets = $this->expandTargets($subnetOrIp);
        if (!empty($targets) && count($targets) <= 256) {
            foreach ($this->probeHttpCgi($targets) as $ip => $info) {
                $foundByIp[$ip] = array_merge($foundByIp[$ip] ?? [], $info);
            }
        }

        // Map against existing ViscaCamera records
        $existingByIp = [];
        foreach (ViscaCamera::get() as $cam) {
            $existingByIp[$cam->IPAddress] = [
                'id'    => (int) $cam->ID,
                'title' => (string) $cam->Title,
            ];
        }

        $results = [];
        foreach ($foundByIp as $ip => $info) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            $model = $info['model'] ?? 'PTZOptics Move SE';
            $hostname = $info['hostname'] ?? ('PTZOptics-' . str_replace('.', '-', $ip));
            $existing = $existingByIp[$ip] ?? null;

            $results[] = [
                'ip'            => $ip,
                'port'          => (int) ($info['port'] ?? 1259),
                'mac'           => $info['mac'] ?? '',
                'model'         => $model,
                'hostname'      => $hostname,
                'title'         => $existing ? $existing['title'] : $hostname,
                'source'        => $info['source'] ?? 'Multicast',
                'alreadyExists' => $existing !== null,
                'existingId'    => $existing['id'] ?? 0,
            ];
        }

        usort($results, fn(array $a, array $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
        return $results;
    }

    /**
     * Reassigns a PTZOptics camera's network configuration even when it sits on a foreign subnet
     * (e.g., camera at 192.168.100.4 while host is 192.168.1.x).
     */
    public function updateCameraNetwork(
        string $currentIp,
        string $newIp,
        string $netmask = '255.255.255.0',
        string $gateway = '',
        bool $dhcp = false,
        string $username = 'admin',
        string $password = 'admin'
    ): array {
        if (!filter_var($currentIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException("Invalid current camera IP: {$currentIp}");
        }
        if (!$dhcp && !filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException("Invalid target IP address: {$newIp}");
        }
        if ($gateway === '' && !$dhcp) {
            $parts = explode('.', $newIp);
            $gateway = "{$parts[0]}.{$parts[1]}.{$parts[2]}.1";
        }

        $cleanupCmd = null;
        $routeAdded = false;

        try {
            // If running on Linux (e.g. Raspberry Pi host-network Docker) and camera is on another subnet,
            // temporarily add an alias IP on the camera's /24 so direct TCP HTTP CGI & ONVIF requests succeed.
            if (!$this->isSame24Subnet($currentIp)) {
                [$aliasIp, $iface] = $this->attachTemporarySubnetAlias($currentIp);
                if ($aliasIp && $iface) {
                    $routeAdded = true;
                    $cleanupCmd = sprintf('ip addr del %s/24 dev %s 2>/dev/null || true', escapeshellarg($aliasIp), escapeshellarg($iface));
                }
            }

            $appliedVia = [];

            // Method A: PTZOptics Move SE / G2 HTTP CGI network endpoints
            if ($this->pushPtzOpticsHttpNetwork($currentIp, $newIp, $netmask, $gateway, $dhcp, $username, $password)) {
                $appliedVia[] = 'PTZOptics HTTP CGI';
            }

            // Method B: ONVIF Device Management SetNetworkInterfaces SOAP call
            if ($this->pushOnvifSetNetworkInterfaces($currentIp, $newIp, $netmask, $gateway, $dhcp, $username, $password)) {
                $appliedVia[] = 'ONVIF SetNetworkInterfaces';
            }

            if (empty($appliedVia)) {
                $hint = $routeAdded
                    ? "Camera at {$currentIp} was reached on the wire, but rejected credentials or CGI endpoints (check camera admin username/password)."
                    : "Could not reach {$currentIp} directly from this container. Run this from the Raspberry Pi panel (host network) or verify Ethernet link.";
                throw new RuntimeException($hint);
            }

            // Update any matching ViscaCamera record in SilverStripe DB automatically
            $updatedDb = 0;
            if (!$dhcp && $newIp !== $currentIp) {
                foreach (ViscaCamera::get()->filter('IPAddress', $currentIp) as $cam) {
                    $cam->IPAddress = $newIp;
                    $cam->write();
                    $updatedDb++;
                }
            }

            return [
                'success'    => true,
                'oldIp'      => $currentIp,
                'newIp'      => $dhcp ? 'DHCP' : $newIp,
                'methods'    => $appliedVia,
                'updatedDb'  => $updatedDb,
            ];
        } finally {
            if ($cleanupCmd) {
                @shell_exec($cleanupCmd);
            }
        }
    }

    private function probeWsDiscovery(int $timeoutMs): array
    {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return [];
        }

        @socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        @socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 2);
        @socket_bind($sock, '0.0.0.0', 0);
        socket_set_nonblock($sock);

        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $probeXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"'
            . ' xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery"'
            . ' xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
            . '<e:Header>'
            . '<w:MessageID>uuid:' . $uuid . '</w:MessageID>'
            . '<w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
            . '<w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action>'
            . '</e:Header>'
            . '<e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body>'
            . '</e:Envelope>';

        // Send twice for reliability across switches
        @socket_sendto($sock, $probeXml, strlen($probeXml), 0, '239.255.255.250', 3702);
        usleep(40_000);
        @socket_sendto($sock, $probeXml, strlen($probeXml), 0, '239.255.255.250', 3702);

        $found = [];
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $read = [$sock];
            $write = null;
            $except = null;
            $remUs = (int) max(1000, ($deadline - microtime(true)) * 1_000_000);
            if (@socket_select($read, $write, $except, 0, $remUs) <= 0) {
                continue;
            }

            $buf = '';
            $fromIp = '';
            $fromPort = 0;
            while (@socket_recvfrom($sock, $buf, 8192, 0, $fromIp, $fromPort) !== false) {
                // Extract XAddrs IP (crucial because cross-subnet cameras advertise their configured IP inside XAddrs!)
                $deviceIp = $fromIp;
                if (preg_match('/<[^:>]*:?XAddrs[^>]*>([^<]+)</i', $buf, $xm)) {
                    if (preg_match('/https?:\/\/(\d+\.\d+\.\d+\.\d+)/i', $xm[1], $ipm)) {
                        $deviceIp = $ipm[1];
                    }
                }
                if (!filter_var($deviceIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }

                $isPtzOptics = (stripos($buf, 'ptzoptics') !== false || stripos($buf, 'move') !== false || stripos($buf, 'visca') !== false);
                $model = 'PTZOptics Move SE';
                if (preg_match('/hardware\/([^\s<]+)/i', $buf, $hm)) {
                    $model = urldecode($hm[1]);
                } elseif (preg_match('/name\/([^\s<]+)/i', $buf, $nm)) {
                    $model = urldecode($nm[1]);
                } elseif (!$isPtzOptics) {
                    $model = 'ONVIF PTZ Camera';
                }

                $mac = '';
                if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $buf, $mm)) {
                    $mac = strtoupper(str_replace('-', ':', $mm[0]));
                }

                $found[$deviceIp] = [
                    'ip'       => $deviceIp,
                    'port'     => 1259,
                    'mac'      => $mac,
                    'model'    => $model,
                    'hostname' => $model . ' (' . $deviceIp . ')',
                    'source'   => 'ONVIF WS-Discovery',
                ];
            }
        }

        socket_close($sock);
        return $found;
    }

    private function probeMdns(int $timeoutMs): array
    {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return [];
        }

        @socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 255);
        @socket_bind($sock, '0.0.0.0', 0);
        socket_set_nonblock($sock);

        // Build DNS PTR query for _http._tcp.local and _ndi._tcp.local
        $packet = $this->buildMdnsPtrQuery(['_http._tcp.local', '_ndi._tcp.local']);
        @socket_sendto($sock, $packet, strlen($packet), 0, '224.0.0.251', 5353);

        $found = [];
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $read = [$sock];
            $write = null;
            $except = null;
            $remUs = (int) max(1000, ($deadline - microtime(true)) * 1_000_000);
            if (@socket_select($read, $write, $except, 0, $remUs) <= 0) {
                continue;
            }

            $buf = '';
            $fromIp = '';
            $fromPort = 0;
            while (@socket_recvfrom($sock, $buf, 4096, 0, $fromIp, $fromPort) !== false) {
                if (stripos($buf, 'ptzoptics') === false && stripos($buf, 'move') === false) {
                    continue;
                }
                if (!filter_var($fromIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }
                $found[$fromIp] = [
                    'ip'       => $fromIp,
                    'port'     => 1259,
                    'model'    => 'PTZOptics Move SE',
                    'hostname' => 'PTZOptics (' . $fromIp . ')',
                    'source'   => 'mDNS Multicast',
                ];
            }
        }

        socket_close($sock);
        return $found;
    }

    private function buildMdnsPtrQuery(array $names): string
    {
        $header = pack('nnnnnn', 0x0000, 0x0000, count($names), 0, 0, 0);
        $body = '';
        foreach ($names as $name) {
            foreach (explode('.', $name) as $label) {
                $body .= chr(strlen($label)) . $label;
            }
            $body .= "\x00" . pack('nn', 12, 1); // TYPE PTR (12), CLASS IN (1)
        }
        return $header . $body;
    }

    private function probeHttpCgi(array $ips): array
    {
        $found = [];
        $sockets = [];

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            $sock = @stream_socket_client("tcp://{$ip}:80", $errno, $errstr, 0.06, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
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
        @stream_select($read, $write, $except, 0, 220_000);

        foreach ($write as $ip => $sock) {
            @fwrite($sock, "GET /cgi-bin/param.cgi?action=get_device_conf HTTP/1.0\r\nHost: {$ip}\r\n\r\n");
        }

        usleep(200_000);

        foreach ($sockets as $ip => $sock) {
            $data = @fread($sock, 4096);
            fclose($sock);
            if (!$data) {
                continue;
            }
            if (stripos($data, 'ptzoptics') !== false || stripos($data, 'move') !== false || stripos($data, 'devname') !== false) {
                $hostname = 'PTZOptics (' . $ip . ')';
                if (preg_match('/devname\s*=\s*"?([^"\r\n]+)/i', $data, $m)) {
                    $hostname = trim($m[1]);
                }
                $found[$ip] = [
                    'ip'       => $ip,
                    'port'     => 1259,
                    'model'    => 'PTZOptics Move SE',
                    'hostname' => $hostname,
                    'source'   => 'HTTP CGI Probe',
                ];
            }
        }

        return $found;
    }

    private function pushPtzOpticsHttpNetwork(
        string $currentIp,
        string $newIp,
        string $netmask,
        string $gateway,
        bool $dhcp,
        string $username,
        string $password
    ): bool {
        $authHeader = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
        $dhcpFlag = $dhcp ? '1' : '0';

        // PTZOptics Move SE / G2 CGI endpoints
        $urls = [
            "http://{$currentIp}/cgi-bin/param.cgi?action=set_net_conf&dhcp={$dhcpFlag}&ipaddr={$newIp}&netmask={$netmask}&gateway={$gateway}",
            "http://{$currentIp}/cgi-bin/network.cgi?dhcp={$dhcpFlag}&ip={$newIp}&mask={$netmask}&gateway={$gateway}",
        ];

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => $authHeader . "\r\nConnection: close\r\n",
                'timeout'       => 2.5,
                'ignore_errors' => true,
            ],
        ]);

        foreach ($urls as $url) {
            $resp = @file_get_contents($url, false, $ctx);
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('/\s200\s/', $statusLine) && $resp !== false && stripos($resp, '404') === false) {
                return true;
            }
        }

        return false;
    }

    private function pushOnvifSetNetworkInterfaces(
        string $currentIp,
        string $newIp,
        string $netmask,
        string $gateway,
        bool $dhcp,
        string $username,
        string $password
    ): bool {
        $prefixLen = $this->netmaskToPrefix($netmask);
        $dhcpXml = $dhcp ? 'true' : 'false';
        $manualBlock = $dhcp ? '' : "<tt:Manual><tt:Address>{$newIp}</tt:Address><tt:PrefixLength>{$prefixLen}</tt:PrefixLength></tt:Manual>";

        $soap = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:tds="http://www.onvif.org/ver10/device/wsdl"'
            . ' xmlns:tt="http://www.onvif.org/ver10/schema">'
            . '<s:Body>'
            . '<tds:SetNetworkInterfaces>'
            . '<tds:InterfaceToken>eth0</tds:InterfaceToken>'
            . '<tds:NetworkInterface>'
            . '<tt:Enabled>true</tt:Enabled>'
            . '<tt:IPv4><tt:Enabled>true</tt:Enabled>' . $manualBlock . '<tt:DHCP>' . $dhcpXml . '</tt:DHCP></tt:IPv4>'
            . '</tds:NetworkInterface>'
            . '</tds:SetNetworkInterfaces>'
            . '</s:Body></s:Envelope>';

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/soap+xml; charset=utf-8\r\nAuthorization: Basic " . base64_encode("{$username}:{$password}") . "\r\n",
                'content'       => $soap,
                'timeout'       => 2.5,
                'ignore_errors' => true,
            ],
        ]);

        $resp = @file_get_contents("http://{$currentIp}/onvif/device_service", false, $ctx);
        $statusLine = $http_response_header[0] ?? '';
        return (bool) preg_match('/\s200\s/', $statusLine);
    }

    private function isSame24Subnet(string $targetIp): bool
    {
        $localIp = gethostbyname(gethostname());
        if (!filter_var($localIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $a = explode('.', $localIp);
        $b = explode('.', $targetIp);
        return ($a[0] === $b[0] && $a[1] === $b[1] && $a[2] === $b[2]);
    }

    private function attachTemporarySubnetAlias(string $cameraIp): array
    {
        $iface = trim((string) @shell_exec("ip route show default 2>/dev/null | awk '{print \$5; exit}'"));
        if ($iface === '') {
            return [null, null];
        }
        $parts = explode('.', $cameraIp);
        $lastOctet = ((int) $parts[3] === 250) ? 251 : 250;
        $aliasIp = "{$parts[0]}.{$parts[1]}.{$parts[2]}.{$lastOctet}";

        $cmd = sprintf('ip addr add %s/24 dev %s 2>/dev/null', escapeshellarg($aliasIp), escapeshellarg($iface));
        @exec($cmd, $out, $rc);
        if ($rc === 0) {
            usleep(100_000);
            return [$aliasIp, $iface];
        }
        return [null, null];
    }

    private function netmaskToPrefix(string $netmask): int
    {
        $long = ip2long($netmask);
        if ($long === false) {
            return 24;
        }
        return substr_count(decbin($long), '1');
    }

    private function expandTargets(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }
        if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$input];
        }
        if (strpos($input, '/') !== false) {
            [$subnet, $bits] = explode('/', $input, 2);
            $bits = (int) $bits;
            $base = ip2long($subnet);
            if ($base === false || $bits < 24 || $bits > 32) {
                return [];
            }
            $mask = -1 << (32 - $bits);
            $network = $base & $mask;
            $broadcast = $network | (~$mask & 0xFFFFFFFF);
            $ips = [];
            for ($ip = $network + 1; $ip < $broadcast; $ip++) {
                $ips[] = long2ip($ip);
            }
            return $ips;
        }
        return [];
    }
}
