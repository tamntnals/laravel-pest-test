<?php

namespace App\Services;

interface DatabaseService
{
    public function getOrdersByUser($userId): array;
    public function updateOrderStatus($orderId, $status, $priority): bool;
}
