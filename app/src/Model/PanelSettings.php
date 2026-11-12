<?php

namespace App\Model;

use App\Security\VPXPermissionProvider;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\PasswordField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

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

    private static string $singular_name = 'Panel Branding & Security';

    private static string $plural_name = 'Panel Branding & Security';

    private static array $db = [
        'PanelTitle' => 'Varchar(100)',
        'BackgroundOverlayOpacity' => 'Int',
        'BasicPanelPIN' => 'Varchar(255)',
        'BasicPINPermissions' => 'Text',
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

        return FieldList::create(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    TextField::create('PanelTitle', 'Touch Panel Header Title'),
                    UploadField::create('PanelLogo', 'Company Logo')
                        ->setFolderName('av-branding')
                        ->setAllowedFileCategories('image/supported'),
                    UploadField::create('PanelBackground', 'Touch Panel Background Image')
                        ->setFolderName('av-branding')
                        ->setAllowedFileCategories('image/supported'),
                    NumericField::create('BackgroundOverlayOpacity', 'Background Dark Overlay % (0-95)')
                ),
                Tab::create(
                    'Security',
                    TextField::create('NewBasicPanelPIN', 'Basic Shared Login PIN')
                        ->setValue('')
                        ->setAttribute('autocomplete', 'one-time-code')
                        ->setAttribute('style', '-webkit-text-security: disc;'),
                    CheckboxSetField::create(
                        'BasicPINPermissionsList',
                        'Interfaces Accessible via Basic Shared PIN',
                        [
                            VPXPermissionProvider::PERM_ACCESS_MATRIX => 'Video Matrix Control Panel',
                            VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX => 'Aurora DTX Audio Control',
                            VPXPermissionProvider::PERM_ACCESS_LIGHTING_ENTTEC => 'Enttec Lighting Control',
                            VPXPermissionProvider::PERM_ACCESS_DISCOVERY => 'Hardware Discovery & Network Scanner',
                        ]
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

    public function getBasicPINPermissionsList(): array
    {
        if (empty($this->BasicPINPermissions)) {
            return [VPXPermissionProvider::PERM_ACCESS_MATRIX];
        }

        $decoded = json_decode($this->BasicPINPermissions, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setBasicPINPermissionsList($value): void
    {
        if (is_array($value)) {
            $this->BasicPINPermissions = json_encode(array_values($value));
        } elseif (empty($value)) {
            $this->BasicPINPermissions = json_encode([]);
        }
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
        return in_array($permissionCode, $this->getBasicPINPermissionsList(), true);
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
