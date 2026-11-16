<?php

namespace App\Contract;

interface TouchPanelModuleInterface
{
    public static function getModuleTitle(): string;
    public static function getModuleRoute(): string;
    public static function getModulePermission(): string;
    public static function getModuleSortOrder(): int;
}
