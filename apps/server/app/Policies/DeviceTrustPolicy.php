<?php

namespace App\Policies;

use App\Models\DeviceTrust;
use App\Models\User;

class DeviceTrustPolicy
{
    public function view(User $user, DeviceTrust $deviceTrust): bool
    {
        return $this->ownsTrust($user, $deviceTrust);
    }

    public function access(User $user, DeviceTrust $deviceTrust): bool
    {
        return $this->ownsTrust($user, $deviceTrust) && $deviceTrust->isActive();
    }

    private function ownsTrust(User $user, DeviceTrust $deviceTrust): bool
    {
        return (int) $deviceTrust->user_id === (int) $user->getKey();
    }
}
