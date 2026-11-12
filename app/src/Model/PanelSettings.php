<?php

namespace App\Model;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * @property string $PanelTitle
 * @property int $BackgroundOverlayOpacity
 * @property int $PanelLogoID
 * @property int $PanelBackgroundID
 *
 * @method Image PanelLogo()
 * @method Image PanelBackground()
 */
class PanelSettings extends DataObject
{
    private static string $table_name = 'PanelSettings';

    private static string $singular_name = 'Panel Branding';

    private static string $plural_name = 'Panel Branding';

    private static array $db = [
        'PanelTitle' => 'Varchar(100)',
        'BackgroundOverlayOpacity' => 'Int',
    ];

    private static array $defaults = [
        'PanelTitle' => 'VPX AV MATRIX',
        'BackgroundOverlayOpacity' => 82,
    ];

    private static array $has_one = [
        'PanelLogo' => Image::class,
        'PanelBackground' => Image::class,
    ];

    private static array $owns = [
        'PanelLogo',
        'PanelBackground',
    ];

    private static array $summary_fields = [
        'PanelTitle' => 'Touch Panel Title',
        'PanelLogo.CMSThumbnail' => 'Logo',
        'BackgroundOverlayOpacity' => 'Overlay %',
    ];

    public static function current(): self
    {
        $settings = self::get()->first();
        if (!$settings) {
            $settings = self::create();
            $settings->write();
        }
        return $settings;
    }

    public function getCMSFields(): FieldList
    {
        return FieldList::create(
            TextField::create('PanelTitle', 'Touch Panel Header Title'),
            UploadField::create('PanelLogo', 'Company Logo')
                ->setFolderName('av-branding')
                ->setAllowedFileCategories('image/supported'),
            UploadField::create('PanelBackground', 'Touch Panel Background Image')
                ->setFolderName('av-branding')
                ->setAllowedFileCategories('image/supported'),
            NumericField::create('BackgroundOverlayOpacity', 'Background Dark Overlay % (0-95)')
        );
    }

    public function canCreate($member = null, $context = []): bool
    {
        return self::get()->count() === 0 && parent::canCreate($member, $context);
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
