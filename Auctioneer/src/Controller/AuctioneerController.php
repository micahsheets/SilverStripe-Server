<?php

namespace App\Auctioneer\Controller;

use App\Auctioneer\Model\AuctionPanelStation;
use App\Contract\TouchPanelModuleInterface;
use App\Model\PanelSettings;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\PermissionProvider;

class AuctioneerController extends Controller implements TouchPanelModuleInterface, PermissionProvider
{
    public const PERM_ACCESS = 'PANEL_ACCESS_AUCTIONEER';

    private static string $url_segment = 'panel/auctioneer';

    private static array $url_handlers = [
        '' => 'index',
    ];

    private static array $allowed_actions = [
        'index',
    ];

    public static function getModuleTitle(): string
    {
        return 'Auctioneer Login';
    }

    public static function getModuleRoute(): string
    {
        return '/panel/auctioneer';
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
                'name'     => 'Access Auctioneer Lane Microphone Kiosk',
                'category' => 'AV & Control System',
                'help'     => 'Allows 4-inch lane touchscreens to display the Auctioneer PIN login and mic DSP controls.',
            ],
        ];
    }

    protected function init(): void
    {
        parent::init();
        if (!PanelSecurityGuard::isIPAllowed($this->getRequest())) {
            $this->httpError(403, 'Access denied from this network.');
        }
    }

    public function Link($action = null): string
    {
        return self::join_links('panel/auctioneer', $action);
    }

    public function index(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $stationId = (int) $request->getVar('station');
        if ($stationId <= 0) {
            $clientIP = trim((string) $request->getIP());
            if ($clientIP !== '') {
                $matched = AuctionPanelStation::get()->filter('PanelIPAddress', $clientIP)->first();
                if ($matched) {
                    $stationId = (int) $matched->ID;
                }
            }
        }

        $branding = PanelSettings::current();

        return $this->customise([
            'PanelSettings'    => $branding,
            'SiteConfig'       => $branding,
            'InitialStationID' => $stationId,
        ])->renderWith([
            'App\\Auctioneer\\Controller\\TouchPanelAuctioneer',
            'Page',
        ]);
    }

    public function HasOtherModules(): bool
    {
        $settings = \App\Model\PanelSettings::current();
        if (!$settings) {
            return false;
        }
        $otherCount = 0;
        foreach ($settings->ActiveModules() as $mod) {
            $route = "/" . ltrim((string) $mod->Route, "/");
            if ($route !== "/panel/auctioneer" && $route !== "/") {
                $otherCount++;
            }
        }
        return $otherCount > 0;
    }
}

