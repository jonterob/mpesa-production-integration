<?php
date_default_timezone_set('Africa/Nairobi');

// M-PESA PRODUCTION CONFIGURATION
// Replace these with your actual production credentials
$consumerKey        = "YOUR_PRODUCTION_CONSUMER_KEY_HERE";
$consumerSecret     = "YOUR_PRODUCTION_CONSUMER_SECRET_HERE";
$BusinessShortCode  = "YOUR_PRODUCTION_PAYBILL_TILL_HERE"; // e.g., 174379
$Passkey            = "YOUR_PRODUCTION_PASSKEY_HERE";
$AccountReference   = "YOUR_ACCOUNT_REFERENCE_HERE"; // e.g., Company Name or Invoice prefix

// IMPORTANT: Use HTTPS URL for production callback
$callbackUrl        = "YOUR_PRODUCTION_DOMAIN_HERE/mpesa_callback.php"; // e.g., https://yourdomain.com/mpesa_callback.php

// Set environment to production
$environment = "production"; // Change from "sandbox" to "production"

// API endpoints based on environment
if ($environment == "production") {
    $oauth_url = "https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";
    $stkpush_url = "https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest";
} else {
    $oauth_url = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";
    $stkpush_url = "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";
}

// Database configuration
define('DB_HOST', 'YOUR_DB_HOST'); // e.g., localhost
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_DATABASE_USER');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

// Initialize database connection
function getDB() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// Handle STK Push Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'], $_POST['amount'])) {
    header('Content-Type: application/json');
    
    // Validate and format phone number
    $phoneInput = preg_replace('/[^0-9]/', '', $_POST['phone']);
    
    // Remove leading zero or 254 if present
    if (substr($phoneInput, 0, 3) === '254') {
        $phoneInput = substr($phoneInput, 3);
    } elseif (substr($phoneInput, 0, 1) === '0') {
        $phoneInput = substr($phoneInput, 1);
    }
    
    // Validate phone number (should be 9 digits after removing prefix)
    if (!preg_match('/^\d{9}$/', $phoneInput)) {
        echo json_encode([
            'error' => true,
            'message' => 'Invalid phone number. Please enter a valid Safaricom number.'
        ]);
        exit;
    }
    
    $fullPhone = '254' . $phoneInput;
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_INT);
    
    if (!$amount || $amount < 1 || $amount > 150000) {
        echo json_encode([
            'error' => true,
            'message' => 'Amount must be between 1 and 150,000 KES.'
        ]);
        exit;
    }

    // Generate OAuth token
    $credentials = base64_encode("$consumerKey:$consumerSecret");
    
    $ch = curl_init($oauth_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Basic $credentials",
        "Cache-Control: no-cache"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Enable in production
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch) || $httpCode !== 200) {
        error_log("OAuth token generation failed: " . curl_error($ch));
        echo json_encode([
            'error' => true,
            'message' => 'Failed to initialize payment. Please try again.'
        ]);
        curl_close($ch);
        exit;
    }
    
    curl_close($ch);
    
    $responseData = json_decode($response, true);
    $access_token = $responseData['access_token'] ?? '';

    if (empty($access_token)) {
        error_log("Failed to get access token: " . $response);
        echo json_encode([
            'error' => true,
            'message' => 'Authentication failed. Please try again.'
        ]);
        exit;
    }

    // Prepare STK Push request
    $timestamp = date('YmdHis');
    $password = base64_encode($BusinessShortCode . $Passkey . $timestamp);
    
    $stk_request = [
        "BusinessShortCode" => $BusinessShortCode,
        "Password" => $password,
        "Timestamp" => $timestamp,
        "TransactionType" => "CustomerPayBillOnline",
        "Amount" => $amount,
        "PartyA" => $fullPhone,
        "PartyB" => $BusinessShortCode,
        "PhoneNumber" => $fullPhone,
        "CallBackURL" => $callbackUrl,
        "AccountReference" => $AccountReference . "_" . time(), // Add unique identifier
        "TransactionDesc" => "Payment for goods/services"
    ];

    // Send STK Push
    $ch = curl_init($stkpush_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $access_token"
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stk_request));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch) || $httpCode !== 200) {
        error_log("STK Push failed: " . curl_error($ch));
        echo json_encode([
            'error' => true,
            'message' => 'Failed to send payment request. Please try again.'
        ]);
        curl_close($ch);
        exit;
    }
    
    curl_close($ch);
    
    $resultData = json_decode($result, true);
    
    // Save initial transaction to database
    $db = getDB();
    if ($db) {
        try {
            $stmt = $db->prepare("INSERT INTO transactions 
                (phone, amount, merchant_request_id, checkout_request_id, response_code, status, created_at) 
                VALUES (:phone, :amount, :merchant_request, :checkout_request, :response_code, 'pending', NOW())");
            
            $stmt->execute([
                ':phone' => $fullPhone,
                ':amount' => $amount,
                ':merchant_request' => $resultData['MerchantRequestID'] ?? '',
                ':checkout_request' => $resultData['CheckoutRequestID'] ?? '',
                ':response_code' => $resultData['ResponseCode'] ?? ''
            ]);
        } catch (PDOException $e) {
            error_log("Failed to save transaction: " . $e->getMessage());
        }
    }
    
    echo json_encode($resultData);
    exit;
}

// AJAX Polling: Check Transaction Status
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'], $_GET['phone'])) {
    header('Content-Type: application/json');
    
    $phoneInput = preg_replace('/[^0-9]/', '', $_GET['phone']);
    if (substr($phoneInput, 0, 3) !== '254') {
        if (substr($phoneInput, 0, 1) === '0') {
            $phoneInput = '254' . substr($phoneInput, 1);
        } else {
            $phoneInput = '254' . $phoneInput;
        }
    }
    
    // For production, query database instead of log file
    $db = getDB();
    if ($db) {
        try {
            $stmt = $db->prepare("SELECT status, amount, mpesa_receipt FROM transactions 
                WHERE phone = :phone ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([':phone' => $phoneInput]);
            $transaction = $stmt->fetch();
            
            if ($transaction) {
                echo json_encode([
                    'status' => $transaction['status'],
                    'amount' => $transaction['amount'],
                    'receipt' => $transaction['mpesa_receipt']
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Failed to check transaction: " . $e->getMessage());
        }
    }
    
    // Fallback to file-based checking
    $callbackFile = __DIR__ . "/logs/mpesa_callbacks.log";
    $lines = file_exists($callbackFile) ? file($callbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $status = "pending";
    $amount = null;
    $receipt = null;

    // Read file backwards (newest first)
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $json = json_decode($lines[$i], true);
        if (isset($json['phone']) && $json['phone'] == $phoneInput) {
            $resultCode = $json['result_code'] ?? 1;
            $status = ($resultCode == 0) ? "completed" : "failed";
            if ($status === 'completed') {
                $amount = $json['amount'];
                $receipt = $json['receipt'];
            }
            break;
        }
    }

    echo json_encode(['status' => $status, 'amount' => $amount, 'receipt' => $receipt]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M-Pesa Payment - Production</title>
<link rel="icon" type="image/svg+xml" href="images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
    --primary-color: #4CAF50;
    --danger-color: #f44336;
    --success-color: #4CAF50;
    --warning-color: #ff9800;
}

body { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
}
.card { 
    border-radius: 15px; 
    box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
    transition: all 0.3s ease;
    border: none;
    backdrop-filter: blur(10px);
    background: rgba(255, 255, 255, 0.95);
}
.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 30px 70px rgba(0,0,0,0.4);
}
#status { 
    display: none; 
    transition: all 0.3s ease-in-out;
    border-radius: 10px;
    padding: 1rem;
}
.btn-loading { 
    pointer-events: none; 
    opacity: 0.7;
    position: relative;
}
.btn-loading:after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    top: 50%;
    left: 50%;
    margin-left: -10px;
    margin-top: -10px;
    border: 2px solid #fff;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spinner 0.6s linear infinite;
}
@keyframes spinner {
    to {transform: rotate(360deg);}
}
.input-group-text {
    background: linear-gradient(45deg, #667eea, #764ba2);
    color: white;
    border: none;
}
.form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}
.btn-primary {
    background: linear-gradient(45deg, #667eea, #764ba2);
    border: none;
    padding: 12px;
    font-weight: 600;
    letter-spacing: 0.5px;
}
.btn-primary:hover {
    background: linear-gradient(45deg, #764ba2, #667eea);
    transform: scale(1.02);
}
</style>
</head>
<body>

<div class="container">
    <div class="card p-5 mx-auto" style="max-width: 500px;">
        <div class="text-center mb-4">
            <img src="https://safaricom.co.ke/images/logo.png" alt="M-Pesa Logo" height="60" class="mb-3">
            <h3 class="text-dark mb-2">Pay with M-Pesa</h3>
            <p class="text-muted">Enter your details to complete payment</p>
        </div>
        
        <form id="mpesaForm">
            <div class="mb-4">
                <label class="form-label fw-semibold">Phone Number (Safaricom)</label>
                <div class="input-group">
                    <span class="input-group-text">+254</span>
                    <input type="text" class="form-control" id="phone" name="phone" 
                           placeholder="712345678" maxlength="9" pattern="[0-9]{9}" 
                           title="Please enter exactly 9 digits" required>
                </div>
                <div class="form-text text-muted">
                    <i class="fas fa-info-circle"></i> Enter 9 digits only (e.g., 712345678)
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label fw-semibold">Amount (KES)</label>
                <div class="input-group">
                    <span class="input-group-text">KES</span>
                    <input type="number" class="form-control" id="amount" name="amount" 
                           required min="1" max="150000" step="1">
                </div>
                <div class="form-text text-muted">
                    <i class="fas fa-info-circle"></i> Min: 1 KES, Max: 150,000 KES
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 py-3 mb-3" id="payBtn">
                <span class="me-2">💳</span> Pay Now
            </button>
            
            <div class="text-center">
                <small class="text-muted">
                    <i class="fas fa-lock me-1"></i> Secured by M-Pesa
                </small>
            </div>
        </form>
        
        <div id="status" class="alert mt-4" role="alert"></div>
    </div>
</div>

<!-- Toast notifications container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Form validation and submission
const form = document.getElementById('mpesaForm');
const statusDiv = document.getElementById('status');
const payBtn = document.getElementById('payBtn');
let statusInterval = null;

// Helper to show toast notification
function showToast(message, type = 'info') {
    const toastContainer = document.querySelector('.toast-container');
    const toastId = 'toast-' + Date.now();
    const bgColor = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
    
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = `toast align-items-center text-white ${bgColor} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    toastContainer.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    // Remove toast after it's hidden
    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
    });
}

// Show status message
function showStatus(message, type) {
    statusDiv.style.display = 'block';
    statusDiv.className = `alert alert-${type}`;
    statusDiv.innerHTML = message;
}

// Clear previous status
function clearStatus() {
    statusDiv.style.display = 'none';
    statusDiv.innerHTML = '';
}

// Validate phone number
function validatePhone(phone) {
    // Remove any non-numeric characters
    phone = phone.replace(/\D/g, '');
    
    // Remove leading 0 or 254
    if (phone.startsWith('254')) {
        phone = phone.substring(3);
    } else if (phone.startsWith('0')) {
        phone = phone.substring(1);
    }
    
    return phone;
}

// Validate amount
function validateAmount(amount) {
    amount = parseInt(amount);
    return !isNaN(amount) && amount >= 1 && amount <= 150000;
}

form.addEventListener('submit', async e => {
    e.preventDefault();
    
    // Clear any existing interval
    if (statusInterval) {
        clearInterval(statusInterval);
        statusInterval = null;
    }
    
    let phoneInput = document.getElementById('phone').value.trim();
    const amountInput = document.getElementById('amount').value.trim();
    
    // Validate phone
    phoneInput = validatePhone(phoneInput);
    if (!phoneInput || !/^\d{9}$/.test(phoneInput)) {
        showStatus(
            '<i class="fas fa-exclamation-circle me-2"></i>Please enter a valid 9-digit Safaricom number',
            'danger'
        );
        document.getElementById('phone').focus();
        return;
    }
    
    // Validate amount
    if (!validateAmount(amountInput)) {
        showStatus(
            '<i class="fas fa-exclamation-circle me-2"></i>Amount must be between 1 and 150,000 KES',
            'danger'
        );
        document.getElementById('amount').focus();
        return;
    }

    showStatus(
        '<div class="d-flex align-items-center">' +
        '<div class="spinner-border spinner-border-sm me-3" role="status"></div>' +
        '<div>Processing payment, please check your phone for M-Pesa prompt...</div>' +
        '</div>',
        'warning'
    );
    
    payBtn.classList.add('btn-loading');
    payBtn.disabled = true;

    const formData = new FormData(form);
    formData.set('phone', phoneInput); // Send clean phone without 254

    try {
        const response = await fetch('', { 
            method: 'POST', 
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();

        if (result.ResponseCode === "0") {
            showStatus(
                '<i class="fas fa-mobile-alt me-2"></i>' +
                'STK Push sent! Enter your M-Pesa PIN on your phone to complete payment.',
                'info'
            );
            
            // Start checking transaction status
            checkStatus(phoneInput);
        } else {
            throw new Error(result.errorMessage || result.ResponseDescription || 'Payment initiation failed');
        }
    } catch (err) {
        console.error('Payment error:', err);
        showStatus(
            '<i class="fas fa-exclamation-triangle me-2"></i>' +
            (err.message || 'Failed to initiate payment. Please try again.'),
            'danger'
        );
        payBtn.classList.remove('btn-loading');
        payBtn.disabled = false;
    }
});

async function checkStatus(phone) {
    let attempts = 0;
    const maxAttempts = 24; // 2 minutes (5s * 24)
    
    statusInterval = setInterval(async () => {
        attempts++;
        
        try {
            const res = await fetch(`?check=1&phone=${phone}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            
            const data = await res.json();
            
            if (data.status === 'completed') {
                showStatus(
                    '<div class="text-center">' +
                    '<i class="fas fa-check-circle fa-2x mb-3 text-success"></i>' +
                    '<h5 class="text-success">Payment Successful!</h5>' +
                    '<p class="mb-2">Receipt Number: <strong>' + (data.receipt || 'N/A') + '</strong></p>' +
                    '<p class="mb-0">Amount: <strong>KES ' + (data.amount || '0').toLocaleString() + '</strong></p>' +
                    '</div>',
                    'success'
                );
                
                // Show success toast
                showToast('Payment completed successfully!', 'success');
                
                clearInterval(statusInterval);
                statusInterval = null;
                payBtn.classList.remove('btn-loading');
                payBtn.disabled = false;
                
                // Reset form after success
                document.getElementById('phone').value = '';
                document.getElementById('amount').value = '';
                
            } else if (data.status === 'failed') {
                showStatus(
                    '<i class="fas fa-times-circle me-2"></i>' +
                    'Payment failed or was cancelled. Please try again.',
                    'danger'
                );
                
                showToast('Payment failed. Please try again.', 'error');
                
                clearInterval(statusInterval);
                statusInterval = null;
                payBtn.classList.remove('btn-loading');
                payBtn.disabled = false;
                
            } else if (attempts >= maxAttempts) {
                showStatus(
                    '<i class="fas fa-clock me-2"></i>' +
                    'Payment is taking longer than expected. You will receive an SMS confirmation shortly.',
                    'info'
                );
                
                showToast('Check your phone for M-Pesa confirmation message', 'info');
                
                clearInterval(statusInterval);
                statusInterval = null;
                payBtn.classList.remove('btn-loading');
                payBtn.disabled = false;
            }
            
        } catch (err) {
            console.error('Status check error:', err);
            // Don't stop checking on error, just continue
        }
    }, 5000); // Check every 5 seconds
}

// Auto-format phone number input
document.getElementById('phone').addEventListener('input', function(e) {
    let value = this.value.replace(/\D/g, '');
    if (value.length > 9) {
        value = value.slice(0, 9);
    }
    this.value = value;
});

// Prevent negative values in amount
document.getElementById('amount').addEventListener('input', function(e) {
    if (this.value < 0) this.value = 1;
});

// Clean up interval on page unload
window.addEventListener('beforeunload', function() {
    if (statusInterval) {
        clearInterval(statusInterval);
    }
});
</script>
</body>
</html>
