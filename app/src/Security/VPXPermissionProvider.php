<?php

namespace App\Security;

use SilverStripe\Security\PermissionProvider;

class VPXPermissionProvider implements PermissionProvider
{
    public const PERM_ACCESS_MATRIX = 'VPX_ACCESS_MATRIX';
    public const PERM_ACCESS_AUDIO_DTX = 'VPX_ACCESS_AUDIO_DTX';
    public const PERM_ACCESS_LIGHTING_ENTTEC = 'VPX_ACCESS_LIGHTING_ENTTEC';
    public const PERM_ACCESS_DISCOVERY = 'VPX_ACCESS_DISCOVERY';
    public const PERM_ACCESS_CAMERA = 'VPX_ACCESS_CAMERA';
    public const PERM_ACCESS_RODECASTER = 'VPX_ACCESS_RODECASTER';

    public function providePermissions(): array
    {
        return [
            self::PERM_ACCESS_MATRIX => [
                'name' => 'Access Video Matrix Control Panel',
                'category' => 'AV & Control System',
                'help' => 'Allows routing encoders/decoders, triggering presets, and controlling display power.',
            ],
            self::PERM_ACCESS_AUDIO_DTX => [
                'name' => 'Access Aurora DTX Audio Control',
                'category' => 'AV & Control System',
                'help' => 'Allows viewing and adjusting Aurora DTX DSP audio levels, mutes, and zones.',
            ],
            self::PERM_ACCESS_LIGHTING_ENTTEC => [
                'name' => 'Access Enttec Lighting Control',
                'category' => 'AV & Control System',
                'help' => 'Allows triggering Enttec DMX scenes and adjusting lighting zones.',
            ],
            self::PERM_ACCESS_DISCOVERY => [
                'name' => 'Access Hardware Discovery & Network Scanner',
                'category' => 'AV & Control System',
                'help' => 'Allows scanning subnets and importing newly discovered devices into the database.',
            ],
            self::PERM_ACCESS_CAMERA => [
                'name' => 'Access VISCA Camera Control',
                'category' => 'AV Touch Panel',
                'help' => 'Allows controlling PTZ cameras and recalling/saving presets.',
            ],
            self::PERM_ACCESS_RODECASTER => [
                'name' => 'Access RØDECaster Video Control',
                'category' => 'AV & Control System',
                'help' => 'Allows switching scenes, inputs, overlays, and media on RØDECaster Video units.',
            ],
        ];
    }
}
