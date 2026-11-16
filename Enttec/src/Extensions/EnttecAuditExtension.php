<?php

namespace App\Enttec\Extensions;

use App\Enttec\Model\EnttecAuditLog;
use App\Security\PanelSecurityGuard;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

class EnttecAuditExtension extends Extension
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

        EnttecAuditLog::create([
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
