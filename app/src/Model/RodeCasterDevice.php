<?php

namespace App\Model;

use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property string $IPAddress
 * @property int $Port
 * @property int $SceneCount
 * @property int $InputCount
 * @property int $OverlayCount
 * @property int $MediaCount
 * @property string $SceneLabels
 * @property string $InputLabels
 * @property string $OverlayLabels
 * @property string $MediaLabels
 * @property int $SortOrder
 */
class RodeCasterDevice extends DataObject
{
    private static string $table_name = 'RodeCasterDevice';

    private static string $singular_name = 'RØDECaster Video';
    private static string $plural_name = 'RØDECaster Videos';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'IPAddress' => 'Varchar(45)',
        'Port' => 'Int',
        'SceneCount' => 'Int',
        'InputCount' => 'Int',
        'OverlayCount' => 'Int',
        'MediaCount' => 'Int',
        'SceneLabels' => 'Text',
        'InputLabels' => 'Text',
        'OverlayLabels' => 'Text',
        'MediaLabels' => 'Text',
        'SortOrder' => 'Int',
    ];

    private static array $defaults = [
        'Port' => 10024,
        'SceneCount' => 6,
        'InputCount' => 6,
        'OverlayCount' => 6,
        'MediaCount' => 6,
    ];

    private static array $summary_fields = [
        'Title' => 'Name',
        'IPAddress' => 'IP Address',
        'Port' => 'OSC TCP Port',
    ];

    private static string $default_sort = 'SortOrder ASC, Title ASC';

    public function getButtonList(string $type): array
    {
        $countField = $type . 'Count';
        $labelField = $type . 'Labels';
        $count = max(1, (int) ($this->{$countField} ?: 6));

        // Optional newline- or comma-separated custom labels in CMS (e.g., "Wide Shot, Host Cam, Guest Cam")
        $rawLabels = trim((string) $this->{$labelField});
        $custom = $rawLabels !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawLabels))))
            : [];

        $buttons = [];
        for ($i = 1; $i <= $count; $i++) {
            $buttons[] = [
                'index' => $i,
                'label' => $custom[$i - 1] ?? "{$type} {$i}",
            ];
        }

        return $buttons;
    }
}
