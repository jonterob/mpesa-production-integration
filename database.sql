-- Create database for M-Pesa transactions
CREATE DATABASE IF NOT EXISTS mpesa_payments;
USE mpesa_payments;

-- Transactions table
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(15) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    merchant_request_id VARCHAR(100),
    checkout_request_id VARCHAR(100),
    mpesa_receipt VARCHAR(50),
    response_code VARCHAR(10),
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_checkout_request (checkout_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create logs table for callback debugging
CREATE TABLE IF NOT EXISTS callback_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT,
    callback_data JSON,
    result_code INT,
    result_desc VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;