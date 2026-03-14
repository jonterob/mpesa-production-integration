<?php
// Enable error logging (disable display in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Create logs directory if it doesn't exist
if (!file_exists(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Log the callback for debugging
$logFile = __DIR__ . '/logs/mpesa_callbacks.log';
$callbackData = file_get_contents('php://input');
$data = json_decode($callbackData, true);

// Extract transaction details from callback
$phone = '';
$amount = '';
$receipt = '';
$transactionDate = '';
$resultCode = 1;

if (isset($data['Body']['stkCallback'])) {
    $callback = $data['Body']['stkCallback'];
    $resultCode = $callback['ResultCode'] ?? 1;
    $resultDesc = $callback['ResultDesc'] ?? '';
    
    if ($resultCode == 0 && isset($callback['CallbackMetadata']['Item'])) {
        $items = $callback['CallbackMetadata']['Item'];
        foreach ($items as $item) {
            switch ($item['Name']) {
                case 'Amount':
                    $amount = $item['Value'] ?? '';
                    break;
                case 'MpesaReceiptNumber':
                    $receipt = $item['Value'] ?? '';
                    break;
                case 'PhoneNumber':
                    $phone = $item['Value'] ?? '';
                    break;
                case 'TransactionDate':
                    $transactionDate = $item['Value'] ?? '';
                    break;
            }
        }
    }
    
    // Save to log file with structured data
    $logEntry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'phone' => $phone,
        'amount' => $amount,
        'receipt' => $receipt,
        'transaction_date' => $transactionDate,
        'result_code' => $resultCode,
        'result_desc' => $resultDesc,
        'full_callback' => $data
    ], JSON_PRETTY_PRINT);
    
    file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
    
    // TODO: Update your database here with the transaction status
    /*
    Example database update:
    
    $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
    $stmt = $db->prepare("UPDATE transactions SET 
        status = :status, 
        mpesa_receipt = :receipt, 
        updated_at = NOW() 
        WHERE phone = :phone AND amount = :amount AND status = 'pending'");
    
    $stmt->execute([
        ':status' => ($resultCode == 0) ? 'completed' : 'failed',
        ':receipt' => $receipt,
        ':phone' => $phone,
        ':amount' => $amount
    ]);
    */
}

// Respond to Safaricom - always return success to acknowledge receipt
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
?>