<?php

namespace App\Form;

use SilverStripe\Forms\CheckboxSetField;

class SafeCheckboxSetField extends CheckboxSetField
{
    protected function stringDecode($value)
    {
        if (is_array($value)) {
            return $value;
        }
        return parent::stringDecode($value);
    }
}
