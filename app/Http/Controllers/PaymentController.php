<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; // Import the Log facade

class PaymentController extends Controller
{
    // Create method
    public function create(Request $request)
    {
        $params = [
            'transaction_details' => [
                'order_id' => Str::uuid(),
                'gross_amount' => $request->price,
            ],
            'item_details' => [
                [
                    'price' => $request->price,
                    'quantity' => 1,
                    'name' => $request->item_name,
                ]
            ],
            'customer_details' => [
                'first_name' => $request->customer_first_name,
                'email' => $request->customer_email,
            ],
            'enabled_payments' => ['credit_card', 'bca_va', 'bni_va', 'bri_va']
        ];

        // Basic Auth with server key
        $auth = base64_encode(env('MIDTRANS_SERVER_KEY') . ':'); // Append ':' for the correct format
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => "Basic $auth"
        ];

        // Log the parameters and headers for debugging
        Log::info('Midtrans API Parameters:', $params);
        Log::info('Midtrans API Headers:', $headers);

        // Make the API call with SSL verification disabled
        $response = Http::withHeaders($headers)
            ->withOptions(['verify' => false]) // Disable SSL verification for testing
            ->post('https://app.sandbox.midtrans.com/snap/v1/transactions', $params);

        // Decode the response from Midtrans
        $response = json_decode($response->body());

        // Check for errors in the response
        if ($response->failed()) {
            // Log the response data for debugging
            Log::error('Midtrans API Error:', [
                'response' => $response,
                'params' => $params,
                'headers' => $headers
            ]);
            return response()->json(['error' => $response->error_messages ?? 'Transaction failed'], 500);
        }

        // Save the payment data to the database
        $payment = new Payment;
        $payment->order_id = $params['transaction_details']['order_id'];
        $payment->status = 'pending';
        $payment->price = $request->price;
        $payment->customer_first_name = $request->customer_first_name;
        $payment->customer_email = $request->customer_email;
        $payment->item_name = $request->item_name;
        $payment->checkout_link = $response->redirect_url ?? null; // Handle case if there's no redirect URL
        $payment->save();

        return response()->json($response);
    }

    public function webhook(Request $request)
    {
        // Encode the Midtrans server key for Basic Auth
        $auth = base64_encode(env('MIDTRANS_SERVER_KEY') . ':');
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => "Basic $auth"
        ];

        // Log the entire incoming webhook request for debugging
        Log::info('Received Webhook Request:', $request->all());

        // Safely extract 'order_id' and 'transaction_id' from the request
        $order_id = $request->input('order_id');
        $transaction_id = $request->input('transaction_id');

        // Log 'transaction_id' if available
        if ($transaction_id) {
            Log::info('Transaction ID:', ['transaction_id' => $transaction_id]);
        }

        // Check if 'order_id' is present
        if (!$order_id) {
            Log::error('Webhook request missing order_id:', $request->all());
            return response()->json(['error' => 'Missing order_id in request'], 400);
        }

        // Construct the full URL to fetch transaction details from Midtrans
        $url = "https://api.sandbox.midtrans.com/v2/{$order_id}/status";

        // Log the 'order_id' and the full URL being requested
        Log::info('Requesting Midtrans Transaction Details:', [
            'order_id' => $order_id,
            'url' => $url
        ]);

        try {
            // Make the API call to Midtrans with SSL verification disabled (only for sandbox)
            $response = Http::withHeaders($headers)
                ->withOptions(['verify' => false]) // Disable SSL verification for testing
                ->get($url);

            // Check if the HTTP request failed
            if ($response->failed()) {
                Log::error('Failed to fetch transaction data from Midtrans:', [
                    'response_body' => $response->body(),
                    'request_data' => $request->all()
                ]);

                // Optionally log 'transaction_id' if available
                if ($transaction_id) {
                    Log::error('Transaction failed:', ['transaction_id' => $transaction_id]);
                }

                return response()->json(['error' => 'Failed to fetch transaction data from Midtrans.'], 500);
            }

            // Decode the JSON response
            $responseData = $response->json();

            // Ensure 'order_id' exists in the response data
            if (!isset($responseData['order_id'])) {
                Log::error('Invalid response from Midtrans API:', [
                    'response_body' => $response->body(),
                    'request_data' => $request->all()
                ]);

                // Optionally log 'transaction_id' if available
                if ($transaction_id) {
                    Log::error('Invalid response for transaction:', ['transaction_id' => $transaction_id]);
                }

                return response()->json(['error' => 'Invalid response from Midtrans.'], 500);
            }

            // Fetch the corresponding payment record from the database
            $payment = Payment::where('order_id', $responseData['order_id'])->first();

            if (!$payment) {
                Log::error('Payment record not found for order_id:', ['order_id' => $responseData['order_id']]);
                return response()->json(['error' => 'Payment record not found'], 404);
            }

            // Prevent re-processing if payment is already settled or captured
            if (in_array($payment->status, ['settlement', 'capture'])) {
                Log::info('Payment has already been processed:', ['order_id' => $payment->order_id]);
                return response()->json('Payment has already been processed');
            }

            // Update payment status based on transaction status from Midtrans
            switch ($responseData['transaction_status']) {
                case 'capture':
                    $payment->status = 'capture';
                    break;
                case 'settlement':
                    $payment->status = 'settlement';
                    break;
                case 'pending':
                    $payment->status = 'pending';
                    break;
                case 'deny':
                    $payment->status = 'deny';
                    break;
                case 'expire':
                    $payment->status = 'expire';
                    break;
                case 'cancel':
                    $payment->status = 'cancel';
                    break;
                default:
                    Log::warning('Unhandled transaction status:', [
                        'transaction_status' => $responseData['transaction_status'],
                        'order_id' => $responseData['order_id']
                    ]);
                    break;
            }

            // Save the updated payment status
            $payment->save();

            // Log the successful update
            Log::info('Payment status updated successfully:', [
                'order_id' => $payment->order_id,
                'new_status' => $payment->status
            ]);

            return response()->json('success');
        } catch (\Exception $e) {
            // Catch any unexpected exceptions and log them
            Log::error('Exception occurred while processing webhook:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
