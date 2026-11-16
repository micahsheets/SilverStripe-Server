<?php

namespace App\Extension;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Member;

class MemberPINExtension extends Extension
{
    private static array $db = [
        'PanelPIN' => 'Varchar(255)',
    ];

    protected ?string $rawPanelPIN = null;

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('PanelPIN');

        $hasPin = !empty($this->owner->PanelPIN);
        $placeholder = $hasPin
            ? '•••• (PIN is set — enter new 4-8 digits to change)'
            : 'Enter 4-8 numeric digits';

        // Use TextField + CSS masking so browser password managers never autofill CMS passwords into this field
        $pinField = TextField::create('NewPanelPIN', 'Touch Panel PIN')
            ->setValue('')
            ->setAttribute('placeholder', $placeholder)
            ->setAttribute('inputmode', 'numeric')
            ->setAttribute('pattern', '[0-9]*')
            ->setAttribute('autocomplete', 'one-time-code')
            ->setAttribute('style', '-webkit-text-security: disc;')
            ->setDescription(
                'Optional 4 to 8 digit numeric PIN for quick login on the AV touch panel. ' .
                'Leave blank to keep the existing PIN.'
            );

        $fields->addFieldToTab('Root.Main', $pinField);
    }

    public function setNewPanelPIN(?string $value): void
    {
        $trimmed = trim((string) $value);
        if ($trimmed !== '') {
            $this->rawPanelPIN = $trimmed;
        }
    }

    protected function resolveSubmittedPIN(): ?string
    {
        if ($this->rawPanelPIN !== null) {
            return $this->rawPanelPIN;
        }

        if (Injector::inst()->has(HTTPRequest::class)) {
            $req = Injector::inst()->get(HTTPRequest::class);
            $posted = trim((string) $req->postVar('NewPanelPIN'));
            if ($posted !== '') {
                $this->rawPanelPIN = $posted;
                return $this->rawPanelPIN;
            }
        }

        return null;
    }

    public function validate(ValidationResult $result): void
    {
        $pin = $this->resolveSubmittedPIN();
        if ($pin === null) {
            return;
        }

        if (!preg_match('/^\d{4,8}$/', $pin)) {
            $result->addFieldError(
                'NewPanelPIN',
                'Touch Panel PIN must be 4 to 8 numeric digits only.'
            );
            return;
        }

        foreach (Member::get() as $otherMember) {
            if ((int) $otherMember->ID === (int) $this->owner->ID) {
                continue;
            }
            if (!empty($otherMember->PanelPIN) && password_verify($pin, (string) $otherMember->PanelPIN)) {
                $result->addFieldError(
                    'NewPanelPIN',
                    'This Touch Panel PIN is already assigned to another user. Please choose a unique PIN.'
                );
                return;
            }
        }
    }

    public function onBeforeWrite(): void
    {
        $pin = $this->resolveSubmittedPIN();
        if ($pin !== null) {
            $this->owner->PanelPIN = password_hash($pin, PASSWORD_DEFAULT);
            $this->rawPanelPIN = null;
        }
    }

    public function verifyPanelPIN(string $pin): bool
    {
        if (empty($this->owner->PanelPIN)) {
            return false;
        }

        return password_verify($pin, (string) $this->owner->PanelPIN);
    }
}
