<?php

namespace App\P300\Extensions;

use App\P300\Model\P300AuditLog;
use App\P300\Model\P300Device;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;

class P300AuditExtension extends Extension
{
    public function onPanelAudit(
        string $type,
        string $summary,
        ?string $command = null,
        bool $success = true,
        ?string $error = null,
        ?P300Device $target = null
    ): void {
        $req = Controller::curr()?->getRequest()
            ?? (Injector::inst()->has(HTTPRequest::class) ? Injector::inst()->get(HTTPRequest::class) : null);

        P300AuditLog::create([
            'Action'         => $type,
            'Summary'        => $summary,
            'Command'        => $command,
            'Success'        => $success,
            'ErrorMessage'   => $error,
            'Actor'          => $req ? PanelSecurityGuard::getActorLabel($req) : 'System',
            'TargetDeviceID' => $target?->ID ?? 0,
        ])->write();
    }
}
