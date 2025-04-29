<?php

namespace App\Console\Commands\Amazon;

use App\Http\Controllers\SyncJobController;
use App\Models\RetailEdgeProduct;
use App\Services\Amazon\AmazonSpApiService;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Amazon\AmazonOrder;
use App\Models\Amazon\AmazonOrderItem;
use App\Services\RetailEdgeService;

class GetOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'amazonGetOrders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Amazon';
        $jobType = 'amazonGetOrders';
        $job = SyncJobController::getJob($jobType, $marketplace);

        if ($job->isRunning()) {
            Log::info("$marketplace $jobType is already running.");
            return;
        }

        try {
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            $this->getOrders();
            $this->pushOrdersToRetailEdge();
        } catch (\Exception $e) {
            report($e);
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            var_dump($e->getMessage());
        }
    }

    public function getOrders()
    {
        try {
            $this->info('getOrders');

            // $dataElements = ['buyerInfo', 'shippingAddress'];
            // $amazonService = new AmazonSpApiService($dataElements);

            $amazonService = new AmazonSpApiService();
            $sellerConnector = $amazonService->getSellerConnector();
            $ordersApi = $sellerConnector->ordersV0();

            $response = $ordersApi->getOrders(
                createdAfter: now()->subDays(2)->format('Y-m-d'),
                marketplaceIds: [config('amazon.spapi.marketplace_id')],
            );

            $dto = $response->dto();

            $orders = $dto->payload->orders;

            foreach ($orders as $order) {
                try {
                    DB::beginTransaction();

                    // Create or update the Amazon order
                    $amazonOrder = $this->updateOrCreateAmazonOrder($order);

                    // Get order items
                    $response = $ordersApi->getOrderItems(
                        $order->amazonOrderId
                    );
                    $orderItemsResponse = $response->dto();

                    // Create or update order items
                    $this->updateOrCreateAmazonOrderItems($order->amazonOrderId, $orderItemsResponse->payload->orderItems);

                    DB::commit();

                    $this->info("Processed order: {$order->amazonOrderId}");
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("Error processing order {$order->amazonOrderId}: {$e->getMessage()}");
                    Log::error("Error processing Amazon order: {$e->getMessage()}", [
                        'order_id' => $order->amazonOrderId ?? 'unknown',
                        'exception' => $e
                    ]);
                }
            }
        } catch (\Exception $e) {
            report($e);
        }
    }


    private function updateOrCreateAmazonOrder($order): AmazonOrder
    {
        // Extract shipping address data if available
        $shippingName = null;
        $shippingAddress1 = null;
        $shippingAddress2 = null;
        $shippingState = null;
        $shippingPostalCode = null;
        $shippingCity = null;
        $shippingCountryCode = null;

        if (isset($order->shippingAddress)) {
            $shippingName = $order->shippingAddress->name ?? null;
            $shippingAddress1 = $order->shippingAddress->addressLine1 ?? null;
            $shippingAddress2 = $order->shippingAddress->addressLine2 ?? null;
            $shippingState = $order->shippingAddress->stateOrRegion ?? null;
            $shippingPostalCode = $order->shippingAddress->postalCode ?? null;
            $shippingCity = $order->shippingAddress->city ?? null;
            $shippingCountryCode = $order->shippingAddress->countryCode ?? null;
        }

        // Extract order total data if available
        $orderTotalCurrencyCode = null;
        $orderTotalAmount = null;

        if (isset($order->orderTotal)) {
            $orderTotalCurrencyCode = $order->orderTotal->currencyCode ?? null;
            $orderTotalAmount = $order->orderTotal->amount ?? null;
        }

        // Extract payment method details if available
        $paymentMethodDetails = null;

        if (isset($order->paymentMethodDetails)) {
            if (is_array($order->paymentMethodDetails)) {
                $paymentMethodDetails = (string) $order->paymentMethodDetails[0];
            } else {
                $paymentMethodDetails = $order->paymentMethodDetails;
            }
        }

        return AmazonOrder::updateOrCreate(
            [
                'amazon_order_id' => $order->amazonOrderId
            ],
            [
                'sales_channel' => $order->salesChannel ?? null,
                'order_status' => $order->orderStatus ?? null,
                'number_of_items_shipped' => $order->numberOfItemsShipped ?? null,
                'order_type' => $order->orderType ?? null,
                'is_premium_order' => isset($order->isPremiumOrder) ? filter_var($order->isPremiumOrder, FILTER_VALIDATE_BOOLEAN) : null,
                'is_prime' => isset($order->isPrime) ? filter_var($order->isPrime, FILTER_VALIDATE_BOOLEAN) : null,
                'fulfillment_channel' => $order->fulfillmentChannel ?? null,
                'number_of_items_unshipped' => $order->numberOfItemsUnshipped ?? null,
                'has_regulated_items' => isset($order->hasRegulatedItems) ? filter_var($order->hasRegulatedItems, FILTER_VALIDATE_BOOLEAN) : null,
                'is_replacement_order' => isset($order->isReplacementOrder) ? filter_var($order->isReplacementOrder, FILTER_VALIDATE_BOOLEAN) : null,
                'is_sold_by_ab' => isset($order->isSoldByAB) ? filter_var($order->isSoldByAB, FILTER_VALIDATE_BOOLEAN) : null,
                'latest_ship_date' => isset($order->latestShipDate) ? Carbon::parse($order->latestShipDate) : null,
                'ship_service_level' => $order->shipServiceLevel ?? null,
                'is_ispu' => isset($order->isISPU) ? filter_var($order->isISPU, FILTER_VALIDATE_BOOLEAN) : null,
                'marketplace_id' => $order->marketplaceId ?? null,
                'buyer_email' => isset($order->buyerInfo) ? $order->buyerInfo?->buyerEmail : null,
                'purchase_date' => isset($order->purchaseDate) ? Carbon::parse($order->purchaseDate) : null,
                'shipping_name' => $shippingName,
                'shipping_address1' => $shippingAddress1,
                'shipping_address2' => $shippingAddress2,
                'shipping_state_or_region' => $shippingState,
                'shipping_postal_code' => $shippingPostalCode,
                'shipping_city' => $shippingCity,
                'shipping_country_code' => $shippingCountryCode,
                'is_access_point_order' => isset($order->isAccessPointOrder) ? filter_var($order->isAccessPointOrder, FILTER_VALIDATE_BOOLEAN) : null,
                'is_business_order' => isset($order->isBusinessOrder) ? filter_var($order->isBusinessOrder, FILTER_VALIDATE_BOOLEAN) : null,
                'order_total_currency_code' => $orderTotalCurrencyCode,
                'order_total_amount' => $orderTotalAmount,
                'payment_method_details' => $paymentMethodDetails,
                'last_update_date' => isset($order->lastUpdateDate) ? Carbon::parse($order->lastUpdateDate) : null,
                'shipment_service_level_category' => $order->shipmentServiceLevelCategory ?? null,
                // 'pushed_to_retail_edge' => 0, // Default to not pushed
            ]
        );
    }

    private function updateOrCreateAmazonOrderItems(string $amazonOrderId, array $orderItems): void
    {
        foreach ($orderItems as $item) {
            // Extract item price data if available
            $itemPriceCurrencyCode = null;
            $itemPriceAmount = null;

            if (isset($item->itemPrice)) {
                $itemPriceCurrencyCode = $item->itemPrice->currencyCode ?? null;
                $itemPriceAmount = $item->itemPrice->amount ?? null;
            }

            // Extract shipping price data if available
            $shippingPriceCurrencyCode = null;
            $shippingPriceAmount = null;

            if (isset($item->shippingPrice)) {
                $shippingPriceCurrencyCode = $item->shippingPrice->currencyCode ?? null;
                $shippingPriceAmount = $item->shippingPrice->amount ?? null;
            }

            // Extract item tax data if available
            $itemTaxCurrencyCode = null;
            $itemTaxAmount = null;

            if (isset($item->itemTax)) {
                $itemTaxCurrencyCode = $item->itemTax->currencyCode ?? null;
                $itemTaxAmount = $item->itemTax->amount ?? null;
            }

            // Extract shipping tax data if available
            $shippingTaxCurrencyCode = null;
            $shippingTaxAmount = null;

            if (isset($item->shippingTax)) {
                $shippingTaxCurrencyCode = $item->shippingTax->currencyCode ?? null;
                $shippingTaxAmount = $item->shippingTax->amount ?? null;
            }

            // var_dump($item);
            // Log::debug(print_r($item, true));
            // $this->info('$item->sellerSku' . $item->sellerSku);

            AmazonOrderItem::updateOrCreate(
                [
                    'amazon_order_id' => $amazonOrderId,
                    'order_item_id' => $item->orderItemId ?? null
                ],
                [
                    'asin' => $item->asin ?? null,
                    'seller_sku' => $item->sellerSku ?? null,
                    'title' => $item->title ?? null,
                    'quantity_ordered' => $item->quantityOrdered ?? null,
                    'quantity_shipped' => $item->quantityShipped ?? null,
                    'item_price_currency_code' => $itemPriceCurrencyCode,
                    'item_price_amount' => $itemPriceAmount,
                    'shipping_price_currency_code' => $shippingPriceCurrencyCode,
                    'shipping_price_amount' => $shippingPriceAmount,
                    'item_tax_currency_code' => $itemTaxCurrencyCode,
                    'item_tax_amount' => $itemTaxAmount,
                    'shipping_tax_currency_code' => $shippingTaxCurrencyCode,
                    'shipping_tax_amount' => $shippingTaxAmount,
                    'condition_id' => $item->conditionId ?? null,
                    'condition_note' => $item->conditionNote ?? null,
                    'is_gift' => isset($item->isGift) ? filter_var($item->isGift, FILTER_VALIDATE_BOOLEAN) : null,
                ]
            );
        }
    }

    private function pushOrdersToRetailEdge()
    {
        $amazonOrders = AmazonOrder::where('pushed_to_retail_edge', 0)->with('orderItems')->get();

        foreach ($amazonOrders as $amazonOrder) {
            $this->info("Processing order {$amazonOrder->amazon_order_id} for Retail Edge");

            if (isset($amazonOrder->shipping_name)) {
                $firstName = $$amazonOrder->shipping_name;
                $lastName = $amazonOrder->shipping_name;
            } else {
                $firstName = 'Amazon';
                $lastName = 'Customer';
            }

            $webOrderLines = [];

            foreach ($amazonOrder->orderItems as $item) {
                // Find the product in RetailEdge by SKU
                // $product = RetailEdgeProduct::where('sku', $item->seller_sku)->first();

                // Get the product from RetailEdge API by SKU
                $product = (new RetailEdgeService)->getActiveItemBySKU($item->seller_sku);

                if (!$product) {
                    Log::warn("Product with SKU {$item->seller_sku} not found in RetailEdge");
                    continue;
                }

                $skuParts = explode("-", $item->seller_sku);
                $stockNum = end($skuParts);

                $webOrderLines[] = [
                    "CategoryID" => $product->CategoryID ?? 1,
                    "SKU" => $item->seller_sku,
                    "StockNum" => $stockNum,
                    "LineNum" => $stockNum,
                    "Quantity" => $item->quantity_ordered,
                    "UnitSellPrice" => $item->item_price_amount ?? 0,
                    "UnitSellTax" => $item->item_tax_amount ?? 0,
                    "UnitFullPrice" => $item->item_price_amount ?? 0,
                    "UnitFullTax" => $item->item_tax_amount ?? 0,
                    "ItemDescription" => $item->title ?? $product->title ?? '',
                    "IsNote" => false,
                    "DesignNumber" => $product->RealDesignNum ?? '',
                ];
            }

            if (empty($webOrderLines)) {
                Log::warn("No valid order lines found for order {$amazonOrder->amazon_order_id}");
                continue;
            }

            // Calculate total price
            $totalPrice = 0;
            $totalShipping = 0;

            foreach ($amazonOrder->orderItems as $item) {
                $totalPrice += ($item->item_price_amount ?? 0) * ($item->quantity_ordered ?? 1);
                $totalShipping += $item->shipping_price_amount ?? 0;
            }

            // Create order data structure
            $orderData = [
                "OrderToUpload" => [
                    "CustomerFirstName" => $firstName,
                    'CustomerID' => $amazonOrder->amazon_order_id,
                    "CustomerLastName" => $lastName,
                    "CustomerEmail" => $amazonOrder->buyer_email ?? '',
                    "CustomerPhone" => $amazonOrder->buyer_phone ?? '',
                    "CustomerAddress" => $amazonOrder->shipping_address1 ?? 'Default',
                    "CustomerSuburb" => $amazonOrder->shipping_city ?? '',
                    "CustomerState" => $amazonOrder->shipping_state_or_region ?? '',
                    "CustomerPostcode" => $amazonOrder->shipping_postal_code ?? '',
                    "CustomerCountry" => $amazonOrder->shipping_country_code ?? '',
                    "DeliveryType" => "ShipToAddress", // Pickup, ShipToAddress
                    "OrderDate" => Carbon::parse($amazonOrder->purchase_date)->timezone('Australia/Melbourne')->format('c'),
                    "OrderID" => $amazonOrder->amazon_order_id,
                    "StoreID" => 1,
                    "Lines" => [
                        "WebOrderLine" => $webOrderLines
                    ],
                    "ShippingPrice" => $totalShipping,
                    "ShippingPriceExTax" => $totalShipping,
                    "ShippingTax" => 0,
                    "TotalFullPrice" => $totalPrice,
                    "TotalFullPriceExTax" => $totalPrice,
                    "TotalFullTax" => 0,
                    "TotalSellPrice" => $totalPrice,
                    "TotalSellPriceExTax" => $totalPrice,
                    "TotalSellTax" => 0,
                ]
            ];

            // Create instance of your service
            $retailEdgeService = new RetailEdgeService();

            try {
                $this->info("Sending order {$amazonOrder->amazon_order_id} to Retail Edge");
                Log::info("Sending order {$amazonOrder->amazon_order_id} to Retail Edge");

                // Make the call
                $response = $retailEdgeService->call('UploadWebOrder', $orderData);

                // Get the last request for debugging
                $lastRequest = $retailEdgeService->getEwebSoapClient()->__getLastRequest();
                $this->info("Request XML:\n" . $lastRequest . "\n\n");

                // Handle the response
                if ($response) {
                    $this->info("Order {$amazonOrder->amazon_order_id} uploaded successfully to Retail Edge");
                    Log::info("Order {$amazonOrder->amazon_order_id} uploaded successfully to Retail Edge");
                    $amazonOrder->update(['pushed_to_retail_edge' => 1]);
                }
            } catch (\SoapFault $e) {
                $this->error("SOAP Fault for order {$amazonOrder->amazon_order_id}:");
                $this->error("Fault code: " . $e->faultcode);
                $this->error("Fault string: " . $e->faultstring);

                // Get the last request and response for debugging
                $lastRequest = $retailEdgeService->getEwebSoapClient()->__getLastRequest();
                $lastResponse = $retailEdgeService->getEwebSoapClient()->__getLastResponse();

                $this->error("\nLast Request:\n" . $lastRequest);
                $this->error("\nLast Response:\n" . $lastResponse);

                Log::error("Error pushing Amazon order to Retail Edge", [
                    'order_id' => $amazonOrder->amazon_order_id,
                    'error' => $e->getMessage(),
                    'request' => $lastRequest,
                    'response' => $lastResponse
                ]);
            } catch (\Exception $e) {
                $this->error("General Exception for order {$amazonOrder->amazon_order_id}: " . $e->getMessage());
                Log::error("General error pushing Amazon order to Retail Edge", [
                    'order_id' => $amazonOrder->amazon_order_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
