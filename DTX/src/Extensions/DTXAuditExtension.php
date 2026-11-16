<?php

namespace App\DTX\Extensions;

use App\DTX\Model\DTXAuditLog;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

class DTXAuditExtension extends Extension
{
    public function onPanelAudit(
        string $type,
        string $summary,
        ?string $command = null,
        bool $success = true,
        ?string $error = null,
        ?DataObject $target = null
    ): void {
        $req = Controller::curr()?->getRequest()
            ?? (Injector::inst()->has(HTTPRequest::class) ? Injector::inst()->get(HTTPRequest::class) : null);

        DTXAuditLog::create([
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
