<?php

namespace App\Model;

use App\Form\SafeCheckboxSetField;
use App\Security\PanelPermissionProvider;
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
        'PanelTitle' => 'AV Control',
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
            $firstMod = SystemModule::get()->sort('SortOrder')->first();
            $defaultPerms = $firstMod ? [$firstMod->PermissionCode] : [];
            $this->BasicPINPermissions = json_encode($defaultPerms);
        }
    }

    public function basicPINAllows(string $permissionCode): bool
    {
        if (empty($this->BasicPINPermissions)) {
            $firstMod = SystemModule::get()->sort('SortOrder')->first();
            $allowed = $firstMod ? [$firstMod->PermissionCode, PanelPermissionProvider::PERM_ACCESS_DISCOVERY] : [];
            return in_array($permissionCode, $allowed, true);
        }

        $decoded = json_decode($this->BasicPINPermissions, true);
        if (!is_array($decoded)) {
            return false;
        }

        // Discovery is permitted if the Basic PIN can access at least one module
        if ($permissionCode === PanelPermissionProvider::PERM_ACCESS_DISCOVERY) {
            return count($decoded) > 0;
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
