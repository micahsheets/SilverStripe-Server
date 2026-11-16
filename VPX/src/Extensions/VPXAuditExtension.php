<?php

namespace App\VPX\Extensions;

use App\Security\PanelSecurityGuard;
use App\VPX\Model\VPXAuditLog;
use App\VPX\Model\VPXDevice;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;

class VPXAuditExtension extends Extension
{
    public function onPanelAudit(
        string $type,
        string $summary,
        ?string $command = null,
        bool $success = true,
        ?string $error = null,
        ?VPXDevice $target = null,
        ?VPXDevice $source = null
    ): void {
        $req = Controller::curr()?->getRequest()
            ?? (Injector::inst()->has(HTTPRequest::class) ? Injector::inst()->get(HTTPRequest::class) : null);

        VPXAuditLog::create([
            'Action'         => $type,
            'Summary'        => $summary,
            'Command'        => $command,
            'Success'        => $success,
            'ErrorMessage'   => $error,
            'Actor'          => $req ? PanelSecurityGuard::getActorLabel($req) : 'System',
            'TargetDeviceID' => $target?->ID ?? 0,
            'SourceDeviceID' => $source?->ID ?? 0,
        ])->write();
    }
}
