<?php

namespace App\P300\Controller;

use App\Contract\TouchPanelModuleInterface;
use App\Model\Location;
use App\P300\Model\P300Device;
use App\P300\Service\P300Service;
use App\Security\PanelSecurityGuard;
use App\Traits\TouchPanelControllerTrait;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\SecurityToken;
use Throwable;

class P300Controller extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    use TouchPanelControllerTrait;

    public const PERM_ACCESS = 'PANEL_ACCESS_AUDIO_P300';

    private static string $url_segment = 'panel/p300';

    private static array $allowed_actions = [
        'index',
        'status',
        'mute',
        'gain',
        'preset',
        'flash',
        'command',
    ];

    private static array $url_handlers = [
        'status'  => 'status',
        'mute'    => 'mute',
        'gain'    => 'gain',
        'preset'  => 'preset',
        'flash'   => 'flash',
        'command' => 'command',
        ''        => 'index',
    ];

    public static function getModuleTitle(): string
    {
        return 'Shure P300';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/p300';
    }

    public static function getModulePermission(): string
    {
        return self::PERM_ACCESS;
    }

    public static function getModuleSortOrder(): int
    {
        return 25;
    }

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS => [
                'name'     => 'Access Shure P300 DSP Control',
                'category' => 'AV & Control System',
                'help'     => 'Allows viewing and controlling Shure IntelliMix P300 mutes, gains, and presets on the touch panel.',
            ],
        ];
    }

    protected function init(): void
    {
        parent::init();
        $request = $this->getRequest();

        if (!PanelSecurityGuard::isIPAllowed($request)) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'IP not allowed.'], 403));
        }

        if (!PanelSecurityGuard::isAuthenticated($request)) {
            if (str_starts_with($request->getURL(), 'api/')) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Auth required.'], 401));
            }
            $this->redirect('/panel');
            return;
        }

        if (!PanelSecurityGuard::canAccess($request, self::PERM_ACCESS)) {
            if (str_starts_with($request->getURL(), 'api/')) {
                throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'Permission denied.'], 403));
            }
            $this->redirect('/panel');
            return;
        }
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        return HTTPResponse::create(
            $this->renderWith(['App\\P300\\Controller\\TouchPanelP300', 'Page'])
        );
    }

    public function status(HTTPRequest $request): HTTPResponse
    {
        $locations = array_map(fn(Location $l) => [
            'id'    => (int) $l->ID,
            'title' => $l->Title,
        ], Location::get()->toArray());

        $service = P300Service::singleton();
        $devices = [];

        foreach (P300Device::get() as $dev) {
            try {
                $live = $service->fetchDeviceState($dev);
            } catch (Throwable $e) {
                $live = [
                    'online'         => false,
                    'error'          => $e->getMessage(),
                    'deviceId'       => '',
                    'automixerMuted' => false,
                    'activePreset'   => 0,
                    'presets'        => [],
                    'channels'       => array_map(fn(string $ch) => [
                        'channel'     => $ch,
                        'name'        => P300Service::DEFAULT_CHANNEL_LABELS[$ch] ?? "Channel {$ch}",
                        'defaultName' => P300Service::DEFAULT_CHANNEL_LABELS[$ch] ?? "Channel {$ch}",
                        'group'       => (int) $ch <= 8 ? 'Dante Mic Inputs' : ((int) $ch <= 14 ? 'Line / USB / Mobile Inputs' : 'Outputs'),
                        'muted'       => false,
                        'gainRaw'     => 1100,
                        'gainDb'      => 0.0,
                    ], $dev->getConfiguredChannelList()),
                ];
            }

            $devices[] = array_merge([
                'id'         => (int) $dev->ID,
                'title'      => (string) $dev->Title,
                'ip'         => (string) $dev->IPAddress,
                'port'       => (int) ($dev->Port ?: 2202),
                'locationId' => (int) $dev->LocationID,
            ], $live);
        }

        return $this->jsonResponse([
            'success'   => true,
            'csrfToken' => SecurityToken::inst()->getValue(),
            'locations' => $locations,
            'devices'   => $devices,
        ]);
    }

    public function mute(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $target = (string) ($data['target'] ?? 'automixer');
            $state = (string) ($data['state'] ?? 'TOGGLE');
            $service = P300Service::singleton();

            if ($target === 'automixer' || $target === '21') {
                $muted = $service->toggleAutomixerMute($device, $state);
                return $this->jsonResponse(['success' => true, 'target' => 'automixer', 'muted' => $muted]);
            }

            $muted = $service->setChannelMute($device, $target, $state);
            return $this->jsonResponse(['success' => true, 'target' => $target, 'muted' => $muted]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function gain(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $channel = (string) ($data['channel'] ?? '');
            $gainDb = (float) ($data['gain_db'] ?? 0.0);

            $appliedDb = P300Service::singleton()->setChannelGainDb($device, $channel, $gainDb);
            return $this->jsonResponse(['success' => true, 'channel' => $channel, 'gainDb' => $appliedDb]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function preset(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $presetNumber = (int) ($data['preset'] ?? 0);

            $active = P300Service::singleton()->recallPreset($device, $presetNumber);
            return $this->jsonResponse(['success' => true, 'activePreset' => $active]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function flash(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            P300Service::singleton()->flashLights($device);
            return $this->jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function command(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['success' => false, 'error' => 'POST required.'], 405);
        }

        try {
            $data = $this->parseJson($request);
            $device = $this->findDevice((int) ($data['device_id'] ?? 0));
            $raw = trim((string) ($data['command'] ?? ''));
            if ($raw === '') {
                return $this->jsonResponse(['success' => false, 'error' => 'Command cannot be empty.'], 400);
            }

            $replies = P300Service::singleton()->sendSingle($device, $raw);
            return $this->jsonResponse(['success' => true, 'replies' => $replies]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function findDevice(int $id): P300Device
    {
        $device = P300Device::get()->byID($id);
        if (!$device) {
            throw new HTTPResponse_Exception($this->jsonResponse(['success' => false, 'error' => 'P300 device not found.'], 404));
        }
        return $device;
    }

    private function parseJson(HTTPRequest $request): array
    {
        $body = $request->getBody();
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonResponse(array $payload, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($payload, JSON_UNESCAPED_SLASHES))
            ->setStatusCode($status)
            ->addHeader('Content-Type', 'application/json');
    }
}
