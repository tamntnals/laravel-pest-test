<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\OrderProcessingService;
use App\Services\DatabaseService;
use App\Contracts\APIClient;
use App\DTO\APIResponse;
use App\Models\Order;
use App\Exceptions\APIException;
use App\Exceptions\DatabaseException;
use App\Services\FileHandler;

class OrderProcessingServiceTest extends TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $dbService;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $apiClient;

    /** @var OrderProcessingService */
    private $service;

    /** @var FileHandler */
    private $fileHandler;

    protected function setUp(): void
    {
        $this->dbService = $this->createMock(DatabaseService::class);
        $this->apiClient = $this->createMock(APIClient::class);
        $this->fileHandler = $this->createMock(FileHandler::class);
        $this->service = new OrderProcessingService($this->dbService, $this->apiClient, $this->fileHandler);
    }

    /**
     * Test for type A order with successful CSV creation.
     */
    public function testProcessOrderTypeA_CsvSuccess()
    {
        $userId = 1;
        $order = new Order(1, 'A', 100, false);
        
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);
        
        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'exported', 'low')
            ->willReturn(true);
        
        // Use php://memory to simulate a successful resource opening
        $memoryStream = fopen('php://memory', 'w');
        $this->fileHandler->expects($this->once())
            ->method('open')
            ->willReturn($memoryStream);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('exported', $orders[0]->status);
        $this->assertEquals('low', $orders[0]->priority);
    }

    /**
     * Test for type A order when fopen returns false, so status is updated to 'export_failed'.
     */
    public function testProcessOrderTypeA_CsvFailure()
    {
        $userId = 1;
        $order = new Order(1, 'A', 100, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);
        
        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'export_failed', 'low')
            ->willReturn(true);

        // Simulate FileHandler->open returning false to mimic a file open failure
        $this->fileHandler->expects($this->once())
            ->method('open')
            ->willReturn(false);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('export_failed', $orders[0]->status);
    }

    /**
     * Test for type B order with condition: API returns success, data >= 50, and amount < 100.
     * => Status should be 'processed'
     */
    public function testProcessOrderTypeB_ApiProcessed()
    {
        $userId = 1;
        $order = new Order(2, 'B', 90, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $apiResponse = new APIResponse('success', 60);
        $this->apiClient->method('callAPI')
            ->with($order->id)
            ->willReturn($apiResponse);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'processed', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('processed', $orders[0]->status);
    }

    /**
     * Test for type B order with condition: flag is true.
     * => Status should be 'pending' even though API returns data >= 50.
     */
    public function testProcessOrderTypeB_ApiPending_FlagTrue()
    {
        $userId = 1;
        // Set amount >= 100 to ensure the first branch is not executed.
        $order = new Order(3, 'B', 110, true);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $apiResponse = new APIResponse('success', 60);
        $this->apiClient->method('callAPI')
            ->with($order->id)
            ->willReturn($apiResponse);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'pending', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('pending', $orders[0]->status);
    }

    /**
     * Test for type B order with condition: API returns data < 50.
     * => Status should be 'pending'
     */
    public function testProcessOrderTypeB_ApiPending_DataLessThan50()
    {
        $userId = 1;
        $order = new Order(4, 'B', 90, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $apiResponse = new APIResponse('success', 40);
        $this->apiClient->method('callAPI')
            ->with($order->id)
            ->willReturn($apiResponse);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'pending', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('pending', $orders[0]->status);
    }

    /**
     * Test for type B order with condition: API returns success but amount >= 100.
     * => Status should be 'error'
     */
    public function testProcessOrderTypeB_ApiError()
    {
        $userId = 1;
        $order = new Order(5, 'B', 110, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $apiResponse = new APIResponse('success', 60);
        $this->apiClient->method('callAPI')
            ->with($order->id)
            ->willReturn($apiResponse);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'error', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('error', $orders[0]->status);
    }

    /**
     * Test for type B order when APIClient throws an APIException.
     * => Status should be 'api_failure'
     */
    public function testProcessOrderTypeB_ApiException()
    {
        $userId = 1;
        $order = new Order(7, 'B', 90, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $this->apiClient->method('callAPI')
            ->with($order->id)
            ->will($this->throwException(new APIException("API error")));

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'api_failure', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('api_failure', $orders[0]->status);
    }

    /**
     * Test for type B order when API returns an error (status other than 'success').
     * => Status should be 'api_error'
     */
    public function testProcessOrderTypeB_ApiErrorStatus()
    {
        $userId = 1;
        $order = new Order(6, 'B', 90, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $apiResponse = new APIResponse('fail', 0);
        $this->apiClient->method('callAPI')
            ->with($order->id)
            ->willReturn($apiResponse);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'api_error', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('api_error', $orders[0]->status);
    }

    /**
     * Test for type C order with flag set to true.
     * => Status should be 'completed'
     */
    public function testProcessOrderTypeC_FlagTrue()
    {
        $userId = 1;
        $order = new Order(8, 'C', 50, true);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'completed', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('completed', $orders[0]->status);
    }

    /**
     * Test for type C order with flag set to false.
     * => Status should be 'in_progress'
     */
    public function testProcessOrderTypeC_FlagFalse()
    {
        $userId = 1;
        $order = new Order(9, 'C', 50, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'in_progress', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('in_progress', $orders[0]->status);
    }

    /**
     * Test for order with an unknown type (not A, B, or C).
     * => Status should be 'unknown_type'
     */
    public function testProcessOrder_UnknownType()
    {
        $userId = 1;
        $order = new Order(10, 'D', 50, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'unknown_type', 'low')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('unknown_type', $orders[0]->status);
    }

    /**
     * Test for priority update: if amount > 200 then priority should be 'high'
     */
    public function testProcessOrder_PriorityHigh()
    {
        $userId = 1;
        $order = new Order(11, 'A', 250, false);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        // For type A order, configure fileHandler to successfully open file
        $stream = fopen('php://temp', 'r+');
        $this->fileHandler->expects($this->once())
            ->method('open')
            ->willReturn($stream);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'exported', 'high')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('high', $orders[0]->priority);
    }

    /**
     * Test for the case where updateOrderStatus throws a DatabaseException.
     * => Status should be updated to 'db_error'
     */
    public function testProcessOrder_UpdateOrderStatusDatabaseException()
    {
        $userId = 1;
        $order = new Order(12, 'C', 50, true);
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);

        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'completed', 'low')
            ->will($this->throwException(new DatabaseException("DB error")));

        $orders = $this->service->processOrders($userId);
        $this->assertEquals('db_error', $orders[0]->status);
    }

    /**
     * Test when getOrdersByUser throws an exception.
     * => The processOrders method should return false.
     */
    public function testProcessOrders_GetOrdersException()
    {
        $userId = 1;
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->will($this->throwException(new \Exception("Get orders error")));

        $orders = $this->service->processOrders($userId);
        $this->assertFalse($orders);
    }

    /**
     * Integration test for multiple orders with different types.
     */
    public function testProcessOrders_MultipleOrdersIntegration()
    {
        $userId = 1;
        $orderA = new Order(13, 'A', 100, false);
        $orderB = new Order(14, 'B', 90, false);
        $orderC = new Order(15, 'C', 50, true);
        $orderUnknown = new Order(16, 'X', 50, false);

        // For type A order, configure fileHandler to successfully open file
        $stream = fopen('php://temp', 'r+');
        $this->fileHandler->method('open')->willReturn($stream);

        // For type B order, set up the appropriate APIResponse
        $apiResponse = new APIResponse('success', 60);
        $this->apiClient->method('callAPI')
            ->willReturnMap([
                [$orderB->id, $apiResponse]
            ]);

        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$orderA, $orderB, $orderC, $orderUnknown]);

        // Spy: expect updateOrderStatus to be called for each order
        $this->dbService->expects($this->exactly(4))
            ->method('updateOrderStatus')
            ->willReturn(true);

        $orders = $this->service->processOrders($userId);

        $this->assertEquals('exported', $orders[0]->status);   // Type A
        $this->assertEquals('processed', $orders[1]->status);    // Type B
        $this->assertEquals('completed', $orders[2]->status);    // Type C
        $this->assertEquals('unknown_type', $orders[3]->status); // Unknown type
    }

    /**
     * Test for type A order using the real FileHandler to ensure that FileHandler::open() is called.
     */
    public function testProcessOrderTypeA_RealFileHandler()
    {
        $userId = 1;
        $order = new Order(100, 'A', 100, false);
       
        $this->dbService->method('getOrdersByUser')
            ->with($userId)
            ->willReturn([$order]);
       
        $this->dbService->expects($this->once())
            ->method('updateOrderStatus')
            ->with($order->id, 'exported', 'low')
            ->willReturn(true);
       
        // Use the real FileHandler instead of a mock.
        $realFileHandler = new FileHandler();
        $serviceWithRealFileHandler = new OrderProcessingService(
            $this->dbService,
            $this->apiClient,
            $realFileHandler
        );
       
        $orders = $serviceWithRealFileHandler->processOrders($userId);
        $this->assertEquals('exported', $orders[0]->status);
       
        // Dọn dẹp file CSV được tạo ra
        foreach (glob("orders_type_A_{$userId}_*.csv") as $filename) {
            unlink($filename);
        }
    }
}
