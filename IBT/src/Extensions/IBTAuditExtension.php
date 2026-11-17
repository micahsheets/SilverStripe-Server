<?php

namespace App\IBT\Extensions;

use App\IBT\Model\IBTAuditLog;
use App\IBT\Model\IBTDevice;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Security;

class IBTAuditExtension extends Extension
{
    public function onPanelAudit(
        string $action,
        string $summary,
        string $command = '',
        bool $success = true,
        ?string $errorMessage = null,
        ?IBTDevice $targetDevice = null,
        ?IBTDevice $sourceDevice = null
    ): void {
        $member = Security::getCurrentUser();
        $actor = $member ? ($member->Email ?: $member->FirstName ?: 'CMS User') : 'Touch Panel';

        $log = IBTAuditLog::create([
            'Action'         => $action,
            'Summary'        => $summary,
            'Command'        => $command,
            'Success'        => $success ? 1 : 0,
            'ErrorMessage'   => $errorMessage,
            'Actor'          => $actor,
            'TargetDeviceID' => $targetDevice ? (int) $targetDevice->ID : 0,
            'SourceDeviceID' => $sourceDevice ? (int) $sourceDevice->ID : 0,
        ]);
        $log->write();
    }
}
