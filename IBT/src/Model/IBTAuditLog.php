<?php

namespace App\IBT\Model;

use SilverStripe\ORM\DataObject;

class IBTAuditLog extends DataObject
{
    private static string $table_name = 'IBTAuditLog';
    private static string $singular_name = 'IBT Audit Log';
    private static string $plural_name = 'IBT Audit Logs';
    private static string $default_sort = 'Created DESC';

    private static array $db = [
        'Action'       => 'Varchar(50)',
        'Summary'      => 'Varchar(255)',
        'Command'      => 'Text',
        'Success'      => 'Boolean(1)',
        'ErrorMessage' => 'Text',
        'Actor'        => 'Varchar(100)',
    ];

    private static array $has_one = [
        'TargetDevice' => IBTDevice::class,
        'SourceDevice' => IBTDevice::class,
    ];

    private static array $summary_fields = [
        'Created.Nice' => 'Timestamp',
        'Action'       => 'Action',
        'Summary'      => 'Summary',
        'Actor'        => 'Actor',
        'Success.Nice' => 'Success',
        'ErrorMessage' => 'Error',
    ];

    public function canCreate($member = null, $context = []): bool { return false; }
    public function canEdit($member = null): bool { return false; }
    public function canDelete($member = null): bool { return false; }
}
