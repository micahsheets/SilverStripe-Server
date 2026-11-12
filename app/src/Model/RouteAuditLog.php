<?php

namespace App\Model;

use App\Security\VPXSecurityGuard;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * @property string $ActionType
 * @property string $Summary
 * @property string $CommandSent
 * @property string $Actor
 * @property string $ClientIP
 * @property bool $Success
 * @property string|null $ErrorMessage
 *
 * @property int $TargetDeviceID
 * @property int $SourceDeviceID
 * @property int $PresetID
 *
 * @method VPXDevice TargetDevice()
 * @method VPXDevice SourceDevice()
 * @method RoutePreset Preset()
 */
class RouteAuditLog extends DataObject
{
    private static string $table_name = 'RouteAuditLog';

    private static string $singular_name = 'Audit Log Entry';

    private static string $plural_name = 'Audit Logs';

    private static string $default_sort = 'Created DESC, ID DESC';

    private static array $db = [
        'ActionType' => "Enum('Route,Preset,Command', 'Route')",
        'Summary' => 'Varchar(255)',
        'CommandSent' => 'Varchar(255)',
        'Actor' => 'Varchar(100)',
        'ClientIP' => 'Varchar(45)',
        'Success' => 'Boolean',
        'ErrorMessage' => 'Text',
    ];

    private static array $has_one = [
        'TargetDevice' => VPXDevice::class,
        'SourceDevice' => VPXDevice::class,
        'Preset' => RoutePreset::class,
    ];

    private static array $indexes = [
        'Created' => true,
    ];

    private static array $summary_fields = [
        'Created.Nice' => 'Timestamp',
        'ActionType' => 'Type',
        'Summary' => 'Action Summary',
        'CommandSent' => 'UDP Payload',
        'Actor' => 'Triggered By',
        'ClientIP' => 'Client IP',
        'Success.Nice' => 'OK',
    ];

    public static function record(
        string $actionType,
        string $summary,
        string $commandSent = '',
        bool $success = true,
        ?string $errorMessage = null,
        ?VPXDevice $target = null,
        ?VPXDevice $source = null,
        ?RoutePreset $preset = null
    ): self {
        $actor = 'CLI / System';
        $ip = '';

        if (Injector::inst()->has(HTTPRequest::class)) {
            $request = Injector::inst()->get(HTTPRequest::class);
            $actor = VPXSecurityGuard::getActorLabel($request);
            $ip = (string) $request->getIP();
        }

        $log = self::create([
            'ActionType' => $actionType,
            'Summary' => $summary,
            'CommandSent' => $commandSent,
            'Actor' => $actor,
            'ClientIP' => $ip,
            'Success' => $success,
            'ErrorMessage' => $errorMessage,
            'TargetDeviceID' => $target ? (int) $target->ID : 0,
            'SourceDeviceID' => $source ? (int) $source->ID : 0,
            'PresetID' => $preset ? (int) $preset->ID : 0,
        ]);
        $log->write();

        // Automatically prune expired logs roughly once every 25 writes
        if (random_int(1, 25) === 1) {
            self::pruneOldLogs();
        }

        return $log;
    }

    /**
     * Delete audit log entries older than $days (defaults to VPX_AUDIT_RETENTION_DAYS or 30).
     */
    public static function pruneOldLogs(?int $days = null): int
    {
        if ($days === null) {
            $envDays = Environment::getEnv('VPX_AUDIT_RETENTION_DAYS');
            $days = $envDays !== false ? (int) $envDays : 30;
        }

        if ($days <= 0) {
            return 0;
        }

        $cutoffTimestamp = strtotime(DBDatetime::now()->getValue() . " -{$days} days");
        $cutoffDate = date('Y-m-d H:i:s', $cutoffTimestamp);

        $count = self::get()->filter('Created:LessThan', $cutoffDate)->count();
        if ($count > 0) {
            DB::prepared_query('DELETE FROM "RouteAuditLog" WHERE "Created" < ?', [$cutoffDate]);
        }

        return $count;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canEdit($member = null): bool
    {
        return false;
    }
}
