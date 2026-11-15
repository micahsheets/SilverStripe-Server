<?php

namespace App\Security;

use SilverStripe\Security\PermissionProvider;

class PanelPermissionProvider implements PermissionProvider
{
    public const PERM_ACCESS_DISCOVERY = 'PANEL_ACCESS_DISCOVERY';

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS_DISCOVERY => [
                'name' => 'Access Hardware Discovery & Network Scanner',
                'category' => 'AV & Control System',
                'help' => 'Allows scanning subnets and importing newly discovered devices into the database.',
            ],
        ];
    }
}
