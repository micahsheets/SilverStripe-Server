<?php

namespace App\Visca\Service;

use App\Visca\Model\ViscaCamera;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

class PtzOpticsDiscoveryService
{
    use Injectable;

    /**
     * Cross-subnet discovery for PTZOptics, AIDA Imaging, and OBSBOT cameras using:
     *  1. WS-Discovery / ONVIF Multicast (239.255.255.250:3702)
     *  2. mDNS Multicast (224.0.0.251:5353)
     *  3. Unicast / CIDR HTTP CGI & VISCA-over-IP UDP (1259 / 52381) probes with temporary subnet alias
     */
    public function scan(string $subnetOrIp = '', int $timeoutMs = 1300): array
    {
        $foundByIp = [];

        // 1. WS-Discovery ONVIF Probe
        foreach ($this->probeWsDiscovery($timeoutMs) as $ip => $info) {
            $foundByIp[$ip] = array_merge($foundByIp[$ip] ?? [], $info);
        }

        // 2. mDNS Multicast Probe
        foreach ($this->probeMdns((int) max(600, $timeoutMs * 0.7)) as $ip => $info) {
            $foundByIp[$ip] = array_merge($foundByIp[$ip] ?? [], $info);
        }

        // 3. Unicast / CIDR HTTP CGI & VISCA-over-IP check (local /24 + 192.168.100.0/24 fallback)
        $localIp = trim((string) @shell_exec("ip -o -4 addr show scope global 2>/dev/null | awk '{split(\$4,a,\"/\"); print a[1]; exit}'"));
        $localCidr = filter_var($localIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? preg_replace('/\.\d+$/', '.0/24', $localIp)
            : '';
        $subnetsToScan = $subnetOrIp !== ''
            ? [$subnetOrIp]
            : array_values(array_unique(array_filter([$localCidr, '192.168.100.0/24'])));

        foreach ($subnetsToScan as $cidr) {
            $targets = $this->expandTargets($cidr);
            if (empty($targets) || count($targets) > 256) {
                continue;
            }
            $probeIp = $targets[0];
            $scanCleanup = null;
            if (!$this->isSame24Subnet($probeIp)) {
                [$aliasIp, $iface] = $this->attachTemporarySubnetAlias($probeIp);
                if ($aliasIp && $iface) {
                    $scanCleanup = sprintf(
                        'sudo -n ip addr del %s/24 dev %s 2>/dev/null || true',
                        escapeshellarg($aliasIp),
                        escapeshellarg($iface)
                    );
                }
            }
            try {
                foreach ($this->probeHttpCgi($targets) as $ip => $info) {
                    $foundByIp[$ip] = array_merge($foundByIp[$ip] ?? [], $info);
                }
                foreach ($this->probeViscaUdp($targets, 52381) as $ip => $info) {
                    if (!isset($foundByIp[$ip])) {
                        $foundByIp[$ip] = $info;
                    }
                }
            } finally {
                if ($scanCleanup) {
                    @shell_exec($scanCleanup);
                }
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
            $hostname = $info['hostname'] ?? ('PTZ Camera (' . $ip . ')');
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
     * Reassigns a camera's network configuration even when it sits on a foreign subnet.
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
            if (!$this->isSame24Subnet($currentIp)) {
                [$aliasIp, $iface] = $this->attachTemporarySubnetAlias($currentIp);
                if ($aliasIp && $iface) {
                    $routeAdded = true;
                    $cleanupCmd = sprintf(
                        'sudo -n ip addr del %s/24 dev %s 2>/dev/null || true',
                        escapeshellarg($aliasIp),
                        escapeshellarg($iface)
                    );
                }
            }

            $appliedVia = [];

            if ($this->pushPtzOpticsHttpNetwork($currentIp, $newIp, $netmask, $gateway, $dhcp, $username, $password)) {
                $appliedVia[] = 'PTZOptics / AIDA HTTP CGI';
            }

            if ($this->pushOnvifSetNetworkInterfaces($currentIp, $newIp, $netmask, $gateway, $dhcp, $username, $password)) {
                $appliedVia[] = 'ONVIF SetNetworkInterfaces';
            }

            if (empty($appliedVia)) {
                $hint = $routeAdded
                    ? "Camera at {$currentIp} was reached on the wire, but rejected credentials or CGI endpoints (check camera admin username/password)."
                    : "Could not reach {$currentIp} directly from this container. Run this from the Raspberry Pi panel (host network) or verify Ethernet link.";
                throw new RuntimeException($hint);
            }

            $updatedDb = 0;
            if (!$dhcp && $newIp !== $currentIp) {
                foreach (ViscaCamera::get()->filter('IPAddress', $currentIp) as $cam) {
                    $cam->IPAddress = $newIp;
                    $cam->write();
                    $updatedDb++;
                }
            }

            return [
                'success'   => true,
                'oldIp'     => $currentIp,
                'newIp'     => $dhcp ? 'DHCP' : $newIp,
                'methods'   => $appliedVia,
                'updatedDb' => $updatedDb,
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
                $deviceIp = $fromIp;
                if (preg_match('/<[^:>]*:?XAddrs[^>]*>([^<]+)</i', $buf, $xm)) {
                    if (preg_match('/https?:\/\/(\d+\.\d+\.\d+\.\d+)/i', $xm[1], $ipm)) {
                        $deviceIp = $ipm[1];
                    }
                }
                if (!filter_var($deviceIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }

                $isObsbot = (stripos($buf, 'obsbot') !== false || stripos($buf, 'remo') !== false);
                $isAida = (stripos($buf, 'aida') !== false);
                $isPtzOptics = (stripos($buf, 'ptzoptics') !== false || stripos($buf, 'move') !== false || stripos($buf, 'visca') !== false);

                $model = $isObsbot
                    ? 'OBSBOT PTZ Camera'
                    : ($isAida ? 'AIDA PTZ Camera' : 'PTZOptics Move SE');
                if (preg_match('/hardware\/([^\s<]+)/i', $buf, $hm)) {
                    $model = urldecode($hm[1]);
                } elseif (preg_match('/name\/([^\s<]+)/i', $buf, $nm)) {
                    $model = urldecode($nm[1]);
                } elseif (!$isObsbot && !$isAida && !$isPtzOptics) {
                    $model = 'ONVIF PTZ Camera';
                }

                $mac = '';
                if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $buf, $mm)) {
                    $mac = strtoupper(str_replace('-', ':', $mm[0]));
                }

                $found[$deviceIp] = [
                    'ip'       => $deviceIp,
                    'port'     => ($isObsbot || $isAida) ? 52381 : 1259,
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
                $isObsbot = (stripos($buf, 'obsbot') !== false);
                $isAida = (stripos($buf, 'aida') !== false);
                if (!$isObsbot && !$isAida && stripos($buf, 'ptzoptics') === false && stripos($buf, 'move') === false) {
                    continue;
                }
                if (!filter_var($fromIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }
                $brand = $isObsbot ? 'OBSBOT' : ($isAida ? 'AIDA' : 'PTZOptics');
                $found[$fromIp] = [
                    'ip'       => $fromIp,
                    'port'     => ($isObsbot || $isAida) ? 52381 : 1259,
                    'model'    => $isObsbot ? 'OBSBOT PTZ Camera' : ($isAida ? 'AIDA PTZ Camera' : 'PTZOptics Move SE'),
                    'hostname' => $brand . ' (' . $fromIp . ')',
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
            $body .= "\x00" . pack('nn', 12, 1);
        }
        return $header . $body;
    }

    private function probeHttpCgi(array $ips): array
    {
        $localIps = preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))) ?: [];
        $found = [];
        $sockets = [];

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || in_array($ip, $localIps, true)) {
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
            @fwrite($sock, "GET / HTTP/1.0\r\nHost: {$ip}\r\n\r\n");
        }

        usleep(250_000);

        foreach ($sockets as $ip => $sock) {
            $data = @fread($sock, 4096);
            fclose($sock);
            if (!$data) {
                continue;
            }
            if (preg_match('/^HTTP\/1\.[01]\s+(200|401)\b/m', $data)
                && stripos($data, 'enttec') === false
                && stripos($data, 's-play') === false
            ) {
                $isAida = (stripos($data, 'aida') !== false);
                $isCameraHttp = $isAida
                    || stripos($data, 'ptzoptics') !== false
                    || stripos($data, 'move') !== false
                    || stripos($data, 'camera') !== false
                    || stripos($data, 'ipcamera') !== false
                    || stripos($data, 'video') !== false;

                $viscaPort = $isAida ? 52381 : 1259;
                if (!$isCameraHttp) {
                    foreach ([52381 => true, 1259 => false] as $testPort => $aidaHint) {
                        $vs = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                        if ($vs !== false) {
                            @socket_set_option($vs, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 120000]);
                            $pkt = hex2bin('81090400ff');
                            @socket_sendto($vs, $pkt, strlen($pkt), 0, $ip, $testPort);
                            $vbuf = '';
                            $vfrom = '';
                            $vport = 0;
                            if (@socket_recvfrom($vs, $vbuf, 32, 0, $vfrom, $vport) !== false && strlen($vbuf) >= 3) {
                                $isCameraHttp = true;
                                $isAida = $aidaHint;
                                $viscaPort = $testPort;
                                socket_close($vs);
                                break;
                            }
                            socket_close($vs);
                        }
                    }
                }

                if ($isCameraHttp) {
                    $brand = $isAida ? 'AIDA' : 'PTZOptics';
                    $model = $isAida ? 'AIDA PTZ Camera' : 'PTZOptics Move SE';
                    $hostname = $brand . ' (' . $ip . ')';
                    if (preg_match('/devname\s*=\s*"?([^"\r\n]+)/i', $data, $m)) {
                        $hostname = trim($m[1]);
                    }
                    $found[$ip] = [
                        'ip'       => $ip,
                        'port'     => $viscaPort,
                        'model'    => $model,
                        'hostname' => $hostname,
                        'source'   => 'HTTP / VISCA Probe',
                    ];
                }
            }
        }

        return $found;
    }

    private function probeViscaUdp(array $ips, int $port = 52381): array
    {
        $localIps = preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))) ?: [];
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return [];
        }
        @socket_bind($sock, '0.0.0.0', 0);
        socket_set_nonblock($sock);

        $rawPkt = hex2bin('81090400ff');
        $encapPkt = hex2bin('010000050000000181090400ff');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || in_array($ip, $localIps, true)) {
                continue;
            }
            @socket_sendto($sock, $rawPkt, strlen($rawPkt), 0, $ip, $port);
            @socket_sendto($sock, $encapPkt, strlen($encapPkt), 0, $ip, $port);
        }

        $found = [];
        $deadline = microtime(true) + 0.35;
        while (microtime(true) < $deadline) {
            $read = [$sock];
            $w = null;
            $e = null;
            $remUs = (int) max(1000, ($deadline - microtime(true)) * 1_000_000);
            if (@socket_select($read, $w, $e, 0, $remUs) <= 0) {
                continue;
            }
            $buf = '';
            $fromIp = '';
            $fromPort = 0;
            while (@socket_recvfrom($sock, $buf, 64, 0, $fromIp, $fromPort) !== false) {
                if (strlen($buf) >= 3 && filter_var($fromIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $found[$fromIp] = [
                        'ip'       => $fromIp,
                        'port'     => $port,
                        'model'    => 'OBSBOT / AIDA PTZ Camera',
                        'hostname' => 'PTZ Camera (' . $fromIp . ')',
                        'source'   => 'VISCA-over-IP UDP :' . $port,
                    ];
                }
            }
        }
        socket_close($sock);
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
            $iface = trim((string) @shell_exec("ip -o -4 addr show scope global 2>/dev/null | awk '{print \$2; exit}'")) ?: 'eth0';
        }
        $parts = explode('.', $cameraIp);
        $lastOctet = ((int) $parts[3] === 250) ? 251 : 250;
        $aliasIp = "{$parts[0]}.{$parts[1]}.{$parts[2]}.{$lastOctet}";

        $cmd = sprintf('sudo -n ip addr add %s/24 dev %s 2>/dev/null', escapeshellarg($aliasIp), escapeshellarg($iface));
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
