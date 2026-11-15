<?php

namespace App\Extension;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;

/**
 * @property string|null $PanelTitle
 * @property int $BackgroundOverlayOpacity
 * @property int $PanelLogoID
 * @property int $PanelBackgroundID
 *
 * @method Image PanelLogo()
 * @method Image PanelBackground()
 */
class AVBrandingExtension extends Extension
{
    private static array $db = [
        'PanelTitle' => 'Varchar(100)',
        'BackgroundOverlayOpacity' => 'Int',
    ];

    private static array $defaults = [
        'PanelTitle' => 'AV Control',
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

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->addFieldsToTab('Root.TouchPanelBranding', [
            TextField::create('PanelTitle', 'Touch Panel Header Title')
                ->setDescription('Displayed next to the logo (defaults to "AV Control")'),
            UploadField::create('PanelLogo', 'Company Logo')
                ->setFolderName('av-branding')
                ->setAllowedFileCategories('image/supported')
                ->setDescription('Recommended: Transparent PNG or SVG, max height 40px'),
            UploadField::create('PanelBackground', 'Touch Panel Background Image')
                ->setFolderName('av-branding')
                ->setAllowedFileCategories('image/supported')
                ->setDescription('Optional full-screen wallpaper behind the Panel UI'),
            NumericField::create('BackgroundOverlayOpacity', 'Background Dark Overlay % (0-95)')
                ->setDescription('Keeps buttons readable over bright wallpapers (default: 82)'),
        ]);
    }
}
