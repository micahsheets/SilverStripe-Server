<?php

namespace App\RodeCaster\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\RodeCaster\Model\RodeCasterDevice;
use App\RodeCaster\Service\RodeCasterOSCService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\SecurityToken;

class RodeCasterController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    public const PERM_ACCESS_RODECASTER = 'PANEL_ACCESS_RODECASTER';

    private static array $allowed_actions = [
        'index',
        'status',
        'ping',
        'trigger',
    ];

    private static array $url_handlers = [
        'status'  => 'status',
        'ping'    => 'ping',
        'trigger' => 'trigger',
        ''        => 'index',
    ];

    public static function getModuleTitle(): string
    {
        return 'RØDECaster Video';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/rodecaster';
    }

    public static function getModulePermission(): string
    {
        return self::PERM_ACCESS_RODECASTER;
    }

    public static function getModuleSortOrder(): int
    {
        return 80;
    }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS_RODECASTER => [
                'name'     => 'Access RØDECaster Video Control Panel',
                'category' => 'AV & Control System',
                'help'     => 'Allows switching scenes, inputs, overlays, media, and volume on RØDECaster Video units.',
            ],
        ];
    }

    protected function init()
    {
        parent::init();
        SecurityToken::disable();
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        return HTTPResponse::create($this->renderWith(['App\\RodeCaster\\Controller\\TouchPanelRodeCaster', 'Page']));
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $devices = RodeCasterDevice::get();
        $osc = RodeCasterOSCService::singleton();
        $list = [];

        foreach ($devices as $dev) {
            $layout = $osc->getShowLayout($dev);

            $list[] = [
                'id'         => (int) $dev->ID,
                'name'       => (string) ($dev->Title ?: 'RØDECaster Video'),
                'ip'         => (string) $dev->IPAddress,
                'port'       => (int) ($dev->Port ?: 10024),
                'locationId' => (int) $dev->LocationID,
                'scenes'     => $this->sliceHardwareMap($dev, 'Scene', $layout['scenes'] ?? []),
                'inputs'     => $this->sliceHardwareMap($dev, 'Input', $layout['inputs'] ?? []),
                'overlays'   => $this->sliceHardwareMap($dev, 'Overlay', $layout['overlays'] ?? []),
                'media'      => $this->sliceHardwareMap($dev, 'Media', $layout['media'] ?? []),
            ];
        }

        return $this->jsonResponse([
            'success' => true,
            'devices' => $list,
        ]);
    }

    private function sliceHardwareMap(RodeCasterDevice $dev, string $type, array $hwMap): array
    {
        $countField = $type . 'Count';
        $rawCount = $dev->{$countField};
        $count = $rawCount !== null ? max(0, (int) $rawCount) : 6;

        $result = [];
        for ($i = 1; $i <= $count; $i++) {
            $result[(string) $i] = $hwMap[$i] ?? "{$type} {$i}";
        }

        return $result;
    }

    public function ping(HTTPRequest $request): HTTPResponse
    {
        $devices = RodeCasterDevice::get();
        $osc = RodeCasterOSCService::singleton();

        $onlineMap = [];
        $modeMap = [];
        $stateMap = [];
        $volumeMap = [];

        foreach ($devices as $dev) {
            $id = (string) $dev->ID;
            $info = $osc->getDeviceState($dev);
            $onlineMap[$id] = (bool) ($info['online'] ?? false);
            if (!empty($info['switchingMode'])) {
                $modeMap[$id] = $info['switchingMode'];
            }
            if (!empty($info['padStates'])) {
                $stateMap[$id] = $info['padStates'];
            }
            if (isset($info['masterVolume']) && $info['masterVolume'] !== null) {
                $volumeMap[$id] = (int) $info['masterVolume'];
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'online'  => $onlineMap,
            'modes'   => $modeMap,
            'states'  => $stateMap,
            'volumes' => $volumeMap,
        ]);
    }

    public function trigger(HTTPRequest $request): HTTPResponse
    {
        $body = json_decode((string) $request->getBody(), true) ?: [];
        $deviceId = (int) ($body['deviceId'] ?? $request->postVar('deviceId') ?? 0);
        $action = trim((string) ($body['action'] ?? $request->postVar('action') ?? ''));
        $index = (int) ($body['index'] ?? $request->postVar('index') ?? 0);

        $device = $deviceId > 0
            ? RodeCasterDevice::get()->byID($deviceId)
            : RodeCasterDevice::get()->first();

        if (!$device) {
            return $this->jsonResponse(['success' => false, 'error' => 'RØDECaster device not found'], 404);
        }

        $osc = RodeCasterOSCService::singleton();

        if ($action === 'volume') {
            $result = $osc->setMasterVolume($device, $index);
            return $this->jsonResponse($result, $result['success'] ? 200 : 502);
        }

        $result = $osc->trigger($device, $action, $index);
        return $this->jsonResponse($result, $result['success'] ? 200 : 502);
    }

    private function jsonResponse(array $payload, int $statusCode = 200): HTTPResponse
    {
        $response = HTTPResponse::create(json_encode($payload), $statusCode);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }
}
