<?php

namespace App\Model;

use App\Form\SafeCheckboxSetField;
use App\Security\VPXPermissionProvider;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\DropdownField;

/**
 * @property string $PanelTitle
 * @property int $BackgroundOverlayOpacity
 * @property string $BasicPanelPIN
 * @property string $BasicPINPermissions
 * @property int $PanelLogoID
 * @property int $PanelBackgroundID
 *
 * @method Image PanelLogo()
 * @method Image PanelBackground()
 */
class PanelSettings extends DataObject
{
    private static string $table_name = 'PanelSettings';

    private static string $singular_name = 'Panel Settings';

    private static string $plural_name = 'Panel Settings';

    private static array $db = [
        'PanelTitle' => 'Varchar(100)',
        'PanelTheme' => "Enum('dark,light', 'dark')",
        'BackgroundOverlayOpacity' => 'Int',
        'BasicPanelPIN' => 'Varchar(255)',
        'BasicPINPermissions' => 'Text',
    ];

    private static array $defaults = [
        'PanelTitle' => 'VPX AV MATRIX',
        'PanelTheme' => 'dark',
        'BackgroundOverlayOpacity' => 82,
    ];

    private static array $has_one = [
        'PanelLogo' => Image::class,
        'PanelBackground' => Image::class,
    ];

    private static array $many_many = [
        'ActiveModules' => SystemModule::class,
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

    protected ?string $rawBasicPIN = null;

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
        $hasBasicPin = !empty($this->BasicPanelPIN);
        $placeholder = $hasBasicPin
            ? '•••• (Basic PIN is set — enter new 4-8 digits to change)'
            : 'Enter 4-8 numeric digits';

        $moduleOptions = SystemModule::get()->map('ID', 'Title')->toArray();
        $permissionOptions = SystemModule::get()->map('PermissionCode', 'Title')->toArray();

        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    TextField::create('PanelTitle', 'Touch Panel Header Title'),
                    DropdownField::create(
                        'PanelTheme',
                        'Touch Panel Color Theme',
                        array(
                            'dark' => 'Dark Theme (Default)',
                            'light' => 'Light Theme',
                        )
                    ),
                    UploadField::create('PanelLogo', 'Company Logo')
                        ->setFolderName('av-branding')
                        ->setAllowedFileCategories('image/supported'),
                    UploadField::create('PanelBackground', 'Touch Panel Background Image')
                        ->setFolderName('av-branding')
                        ->setAllowedFileCategories('image/supported'),
                    NumericField::create('BackgroundOverlayOpacity', 'Background Dark Overlay % (0-95)')
                ),
                Tab::create(
                    'Modules',
                    SafeCheckboxSetField::create(
                        'ActiveModules',
                        'Enabled System Modules',
                        $moduleOptions
                    )->setDescription('Select which control modules should appear on the touch panel navigation for this installation.')
                ),
                Tab::create(
                    'Security',
                    TextField::create('NewBasicPanelPIN', 'Basic Shared Login PIN')
                        ->setValue('')
                        ->setAttribute('placeholder', $placeholder)
                        ->setAttribute('autocomplete', 'one-time-code')
                        ->setAttribute('style', '-webkit-text-security: disc;'),
                    SafeCheckboxSetField::create(
                        'BasicPINPermissions',
                        'Interfaces Accessible via Basic Shared PIN',
                        $permissionOptions
                    )->setDescription('Select which control interfaces can be unlocked using the Basic Shared PIN.')
                )
            )
        );
    }

    public function setNewBasicPanelPIN(?string $value): void
    {
        $trimmed = trim((string) $value);
        $this->rawBasicPIN = $trimmed !== '' ? $trimmed : null;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if ($this->rawBasicPIN !== null && !preg_match('/^\d{4,8}$/', $this->rawBasicPIN)) {
            $result->addFieldError(
                'NewBasicPanelPIN',
                'Basic Shared Login PIN must be 4 to 8 numeric digits only.'
            );
        }

        return $result;
    }

    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if ($this->rawBasicPIN !== null) {
            $this->BasicPanelPIN = password_hash($this->rawBasicPIN, PASSWORD_DEFAULT);
            $this->rawBasicPIN = null;
        }

        if (empty($this->BasicPINPermissions)) {
            $this->BasicPINPermissions = json_encode(array(VPXPermissionProvider::PERM_ACCESS_MATRIX));
        }
    }

    public function verifyBasicPIN(string $pin): bool
    {
        if (empty($this->BasicPanelPIN)) {
            return false;
        }

        return password_verify($pin, $this->BasicPanelPIN);
    }

    public function basicPINAllows(string $permissionCode): bool
    {
        if (empty($this->BasicPINPermissions)) {
            return in_array($permissionCode, array(
                VPXPermissionProvider::PERM_ACCESS_MATRIX,
                VPXPermissionProvider::PERM_ACCESS_DISCOVERY
            ), true);
        }

        $decoded = json_decode($this->BasicPINPermissions, true);
        if (!is_array($decoded)) {
            return false;
        }

        // Discovery shares access with Video Matrix on the Basic PIN
        if ($permissionCode === VPXPermissionProvider::PERM_ACCESS_DISCOVERY) {
            return in_array(VPXPermissionProvider::PERM_ACCESS_MATRIX, $decoded, true);
        }

        return in_array($permissionCode, $decoded, true);
    }

    public static function isModuleRouteEnabled(string $route): bool
    {
        $settings = self::current();
        $cleanRoute = '/' . ltrim($route, '/');

        return $settings->ActiveModules()
            ->filter('Route', $cleanRoute)
            ->exists();
    }

    public function canCreate($member = null, $context = array()): bool
    {
        return self::get()->count() === 0 && parent::canCreate($member, $context);
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
