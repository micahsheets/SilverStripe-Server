<?php

namespace App\Extension;

use App\Model\ProjectorDevice;
use SilverStripe\Core\Extension;

class ProjectorAdminExtension extends Extension
{
    public function updateManagedModels(array &$models): void
    {
        $models[ProjectorDevice::class] = [
            'title' => 'Projectors',
        ];
    }
}
