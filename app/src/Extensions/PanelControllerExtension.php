<?php

namespace App\Extension;

use App\Model\PanelSettings;
use App\Security\PanelPermissionProvider;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

class PanelControllerExtension extends Extension
{
    public function getPanelNavigation()
    {
        $settings = PanelSettings::current();
        $activeModules = $settings->ActiveModules()->sort('SortOrder');
        $nav = ArrayList::create();

        $owner = $this->getOwner();
        $request = ($owner && method_exists($owner, 'getRequest') && $owner->getRequest())
            ? $owner->getRequest()
            : Controller::curr()?->getRequest();

        if (!$request) {
            return $nav;
        }

        $currentPath = trim($request->getURL(), '/');

        foreach ($activeModules as $mod) {
            if (PanelSecurityGuard::canAccess($request, $mod->PermissionCode)) {
                $modPath = trim((string) $mod->Route, '/');
                $isActive = ($currentPath === $modPath || str_starts_with($currentPath, $modPath . '/'));

                $nav->push(ArrayData::create([
                    'Title' => $mod->Title,
                    'Route' => $mod->Route,
                    'IsActive' => $isActive,
                ]));
            }
        }

        return $nav;
    }

    public function getDefaultPanelRoute(): string
    {
        $first = $this->getPanelNavigation()->first();
        return $first ? (string) $first->Route : '/panel/splash';
    }

    public function getCanAccessDiscovery(): bool
    {
        $owner = $this->getOwner();
        $request = ($owner && method_exists($owner, 'getRequest') && $owner->getRequest())
            ? $owner->getRequest()
            : Controller::curr()?->getRequest();

        if (!$request) {
            return false;
        }

        return PanelSecurityGuard::canAccess($request, PanelPermissionProvider::PERM_ACCESS_DISCOVERY);
    }

    public function getPanelTheme(): string
    {
        $settings = PanelSettings::current();
        return $settings->PanelTheme ?: 'dark';
    }

    public function getPanelSettings(): PanelSettings
    {
        return PanelSettings::current();
    }
}
