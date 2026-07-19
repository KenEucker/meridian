<?php

namespace App\Services\Equipment;

use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;

readonly class EquipmentCheckoutResult
{
    public function __construct(
        public EquipmentItem $equipmentItem,
        public EquipmentCheckout $checkout,
        public bool $createdStateChange,
    ) {}
}
