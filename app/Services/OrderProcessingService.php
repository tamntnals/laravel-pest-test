<?php

namespace App\Services;

use App\Services\DatabaseService;
use App\Services\FileHandler;
use App\Contracts\APIClient;
use App\Exceptions\APIException;
use App\Exceptions\DatabaseException;

class OrderProcessingService
{
    private $dbService;
    private $apiClient;
    private $fileHandler;

    public function __construct(DatabaseService $dbService, APIClient $apiClient, FileHandler $fileHandler)
    {
        $this->dbService = $dbService;
        $this->apiClient = $apiClient;
        $this->fileHandler = $fileHandler;
    }

    public function processOrders(int $userId)
    {
        try {
            $orders = $this->dbService->getOrdersByUser($userId);

            foreach ($orders as $order) {
                switch ($order->type) {
                    case 'A':
                        $csvFile = 'orders_type_A_' . $userId . '_' . time() . '.csv';
                        $fileHandle = $this->fileHandler->open($csvFile, 'w');
                        if ($fileHandle !== false) {
                            fputcsv($fileHandle, ['ID', 'Type', 'Amount', 'Flag', 'Status', 'Priority']);

                            fputcsv($fileHandle, [
                                $order->id,
                                $order->type,
                                $order->amount,
                                $order->flag ? 'true' : 'false',
                                $order->status,
                                $order->priority
                            ]);

                            if ($order->amount > 150) {
                                fputcsv($fileHandle, ['', '', '', '', 'Note', 'High value order']);
                            }

                            fclose($fileHandle);
                            $order->status = 'exported';
                        } else {
                            $order->status = 'export_failed';
                        }
                        break;

                    case 'B':
                        try {
                            $apiResponse = $this->apiClient->callAPI($order->id);

                            if ($apiResponse->status === 'success') {
                                if ($apiResponse->data >= 50 && $order->amount < 100) {
                                    $order->status = 'processed';
                                } elseif ($apiResponse->data < 50 || $order->flag) {
                                    $order->status = 'pending';
                                } else {
                                    $order->status = 'error';
                                }
                            } else {
                                $order->status = 'api_error';
                            }
                        } catch (APIException $e) {
                            $order->status = 'api_failure';
                        }
                        break;

                    case 'C':
                        if ($order->flag) {
                            $order->status = 'completed';
                        } else {
                            $order->status = 'in_progress';
                        }
                        break;

                    default:
                        $order->status = 'unknown_type';
                        break;
                }

                $order->priority = ($order->amount > 200) ? 'high' : 'low';

                try {
                    $this->dbService->updateOrderStatus($order->id, $order->status, $order->priority);
                } catch (DatabaseException $e) {
                    $order->status = 'db_error';
                }
            }
            return $orders;
        } catch (\Exception $e) {
            return false;
        }
    }
}
