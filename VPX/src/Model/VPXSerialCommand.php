<?php

namespace App\VPX\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property string $Command
 * @property int $SortOrder
 * @property int $DeviceID
 *
 * @method VPXDevice Device()
 */
class VPXSerialCommand extends DataObject
{
    private static string $table_name = 'VPXSerialCommand';

    private static string $singular_name = 'RS-232 Command';

    private static string $plural_name = 'RS-232 Commands';

    private static string $default_sort = 'SortOrder ASC, ID ASC';

    private static array $db = [
        'Title' => 'Varchar(80)',
        'Command' => 'Varchar(255)',
        'SortOrder' => 'Int',
    ];

    private static array $has_one = [
        'Device' => VPXDevice::class,
    ];

    private static array $summary_fields = [
        'Title' => 'Button Label',
        'Command' => 'RS-232 Command String',
        'SortOrder' => 'Sort Order',
    ];

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TextField::create('Title', 'Button Label')
                ->setDescription('Name shown on the touch panel button (e.g., "HDMI 1", "Mute", "Screen Down")'),
            TextField::create('Command', 'VPX RS-232 Command String')
                ->setDescription('Aurora VPX serial syntax, e.g.: serial 1,9600,8,N,1 \x02IN1\x03 (hex bytes like \x02 and \x0D are automatically converted)'),
            NumericField::create('SortOrder', 'Sort Order')
                ->setDescription('Lower numbers appear first on the touch panel')
        );
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', 'Button Label is required.');
        }

        if (trim((string) $this->Command) === '') {
            $result->addFieldError('Command', 'RS-232 Command String is required.');
        }

        return $result;
    }
}
