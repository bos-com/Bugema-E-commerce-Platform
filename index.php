<?php
session_start();
include 'db_connect.php';

// Enable error reporting for debugging (remove in production)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Initialize guest cart and favorites if not set
if (!isset($_SESSION['guest_cart'])) {
    $_SESSION['guest_cart'] = [];
}
if (!isset($_SESSION['guest_favorites'])) {
    $_SESSION['guest_favorites'] = [];
}

// Sync guest favorites to database upon login
if (isset($_SESSION['user_id']) && !empty($_SESSION['guest_favorites'])) {
    $user_id = $_SESSION['user_id'];
    foreach ($_SESSION['guest_favorites'] as $product_id) {
        $product_id = (int)$product_id;
        $stmt = $conn->prepare("SELECT * FROM favorites WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $stmt_insert = $conn->prepare("INSERT INTO favorites (user_id, product_id) VALUES (?, ?)");
            $stmt_insert->bind_param("ii", $user_id, $product_id);
            if (!$stmt_insert->execute()) {
                error_log("Failed to sync guest favorite for product_id $product_id: " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }
        $stmt->close();
    }
    // Clear guest favorites after syncing
    $_SESSION['guest_favorites'] = [];
}

// Handle add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;
    
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
        $stmt->bind_param("iiii", $user_id, $product_id, $quantity, $quantity);
        if (!$stmt->execute()) {
            error_log("Failed to add to cart for user_id $user_id, product_id $product_id: " . $stmt->error);
        }
        $stmt->close();
    } else {
        if (!isset($_SESSION['guest_cart'][$product_id])) {
            $_SESSION['guest_cart'][$product_id] = $quantity;
        } else {
            $_SESSION['guest_cart'][$product_id] += $quantity;
        }
    }
    
    header("Location: index.php");
    exit();
}

// Handle add/remove from favorites
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_favorite'])) {
    $product_id = (int)$_POST['product_id'];
    
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT * FROM favorites WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $stmt_delete = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND product_id = ?");
            $stmt_delete->bind_param("ii", $user_id, $product_id);
            if (!$stmt_delete->execute()) {
                error_log("Failed to remove favorite for user_id $user_id, product_id $product_id: " . $stmt_delete->error);
            }
            $stmt_delete->close();
        } else {
            $stmt_insert = $conn->prepare("INSERT INTO favorites (user_id, product_id) VALUES (?, ?)");
            $stmt_insert->bind_param("ii", $user_id, $product_id);
            if (!$stmt_insert->execute()) {
                error_log("Failed to add favorite for user_id $user_id, product_id $product_id: " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }
        $stmt->close();
    } else {
        if (in_array($product_id, $_SESSION['guest_favorites'])) {
            $_SESSION['guest_favorites'] = array_diff($_SESSION['guest_favorites'], [$product_id]);
        } else {
            $_SESSION['guest_favorites'][] = $product_id;
        }
        $_SESSION['guest_favorites'] = array_values($_SESSION['guest_favorites']);
    }
    
    header("Location: index.php");
    exit();
}

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    if (!$conn || $conn->connect_error) {
        $response = ['success' => false, 'message' => 'Database connection failed.'];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    $name = trim($_POST['feedback_name']);
    $email = trim($_POST['feedback_email']);
    $message = trim($_POST['feedback_message']);
    $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    // Basic validation
    if (empty($name) || empty($email) || empty($message)) {
        $response = ['success' => false, 'message' => 'All fields are required.'];
    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$/", $email)) {
        $response = ['success' => false, 'message' => 'Invalid email format.'];
    } elseif (strlen($name) > 255 || strlen($email) > 255) {
        $response = ['success' => false, 'message' => 'Name or email exceeds maximum length.'];
    } else {
        $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, message, created_at) VALUES (?, ?, ?, ?, NOW())");
        if (!$stmt) {
            $response = ['success' => false, 'message' => 'Failed to prepare query: ' . $conn->error];
            error_log("Prepare failed: " . $conn->error);
        } else {
            $stmt->bind_param("isss", $user_id, $name, $email, $message);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Feedback submitted successfully!'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to submit feedback: ' . $stmt->error];
                error_log("Failed to submit feedback: " . $stmt->error);
            }
            $stmt->close();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle newsletter subscription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_newsletter'])) {
    $email = trim($_POST['newsletter_email']);
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response = ['success' => false, 'message' => 'Please enter a valid email address.'];
    } else {
        // Check if newsletter_subscribers table exists, if not create it
        $table_check = $conn->query("SHOW TABLES LIKE 'newsletter_subscribers'");
        if ($table_check->num_rows == 0) {
            // Create the table
            $create_table = $conn->query("CREATE TABLE newsletter_subscribers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
        
        $stmt = $conn->prepare("INSERT INTO newsletter_subscribers (email, subscribed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE subscribed_at = NOW()");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            if ($stmt->execute()) {
                $response = ['success' => true, 'message' => 'Thank you for subscribing to our newsletter!'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to subscribe. Please try again.'];
            }
            $stmt->close();
        } else {
            $response = ['success' => false, 'message' => 'Database error. Please try again.'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Get notifications count - UPDATED FOR YOUR TABLE STRUCTURE
$notifications_count = 0;
$recent_notifications = [];
if (isset($_SESSION['user_id'])) {
    // Check if notifications table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($table_check->num_rows > 0) {
        // Count all notifications (since there's no is_read column in your table)
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $notifications_count = $result->fetch_assoc()['count'];
            $stmt->close();
        }
        
        // Get recent notifications for display
        $stmt = $conn->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $recent_notifications = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

// Get favorites count
$favorites_count = 0;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM favorites WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $favorites_count = $result->fetch_assoc()['count'];
    $stmt->close();
} else {
    $favorites_count = count($_SESSION['guest_favorites']);
}

// Get cart count
$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $cart_count = $result->fetch_assoc()['count'] ?? 0;
    $stmt->close();
} else {
    $cart_count = array_sum($_SESSION['guest_cart']);
}

// Set user_email to empty string (no email column in users table)
$user_email = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bugema CampusShop - Bugema University Official Store</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary-green: #091bbeff;
    --secondary-green: #4591e7ff;
    --accent-yellow: #facc15;
    --light-gray: #f3f4f6;
    --dark-gray: #111827;
    --text-gray: #4b5563;
    --white: #ffffff;
    --error-red: #dc2626;
    --success-green: #1059b9ff;
}

body {
    font-family: 'Poppins', sans-serif;
    line-height: 1.6;
    color: var(--dark-gray);
    background: var(--light-gray);
    padding-bottom: 0;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 15px;
}

header {
    background: var(--primary-green);
    color: var(--white);
    padding: 1rem 0;
    position: sticky;
    top: 0;
    z-index: 1000;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.header-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
}

.logo {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.4rem;
    font-weight: 600;
}

.logo-icon {
    width: 35px;
    height: 35px;
    background: var(--accent-yellow);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

.menu-icon {
    display: none;
    font-size: 1.5rem;
    background: none;
    border: none;
    color: var(--white);
    cursor: pointer;
    padding: 5px;
    z-index: 1101;
}

.mobile-menu {
    position: fixed;
    top: 0;
    right: -100%;
    width: 280px;
    height: 100%;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 2rem;
    z-index: 1100;
    transition: right 0.3s ease;
    overflow-y: auto;
    box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1);
}

.mobile-menu.active {
    right: 0;
}

.mobile-menu-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--light-gray);
}

.mobile-menu-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--primary-green);
}

.close-icon {
    font-size: 1.5rem;
    background: none;
    border: none;
    color: var(--dark-gray);
    cursor: pointer;
    padding: 5px;
}

.mobile-nav {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.mobile-nav a {
    color: var(--dark-gray);
    text-decoration: none;
    font-size: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    transition: background 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.mobile-nav a:hover, .mobile-nav a.active {
    background: var(--secondary-green);
    color: var(--white);
}

.mobile-search-bar {
    margin: 1rem 0;
    position: relative;
}

.mobile-username {
    color: var(--dark-gray);
    font-size: 1rem;
    font-weight: 500;
    padding: 0.5rem;
    border-radius: 8px;
    background: var(--light-gray);
    margin-bottom: 1rem;
    text-align: center;
}

.mobile-username a {
    color: var(--primary-green);
    text-decoration: none;
}

.mobile-username a:hover {
    text-decoration: underline;
}

.search-bar {
    flex: 1;
    max-width: 400px;
    margin: 0 1rem;
    position: relative;
}

.search-input {
    width: 100%;
    padding: 10px 40px 10px 15px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    background: var(--white);
    color: var(--dark-gray);
    border: 1px solid var(--text-gray);
}

.search-input::placeholder {
    color: var(--text-gray);
}

.search-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-gray);
    cursor: pointer;
    font-size: 1rem;
}

.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--white);
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    padding: 5px;
}

.search-results.active {
    display: block;
}

.search-results .product-card {
    margin-bottom: 10px;
    padding: 10px;
    border: 1px solid var(--light-gray);
}

.search-results .no-results {
    padding: 10px;
    color: var(--text-gray);
    font-size: 0.9rem;
    text-align: center;
}

.search-results .suggestion {
    padding: 8px 10px;
    cursor: pointer;
    font-size: 0.9rem;
    color: var(--dark-gray);
    border-bottom: 1px solid var(--light-gray);
    transition: background 0.2s ease;
}

.search-results .suggestion:last-child {
    border-bottom: none;
}

.search-results .suggestion:hover,
.search-results .suggestion.selected {
    background: var(--primary-green);
    color: var(--white);
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.header-btn {
    background: var(--accent-yellow);
    color: var(--dark-gray);
    padding: 8px 15px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    transition: background 0.3s ease;
    display: flex;
    align-items: center;
    gap: 5px;
}

.header-btn:hover {
    background: var(--secondary-green);
    color: var(--white);
}

.username a {
    color: var(--white);
    font-size: 1.1rem;
    font-weight: 500;
    padding: 10px 20px;
    border-radius: 8px;
    transition: color 0.3s ease;
    text-decoration: none;
}

.username a:hover {
    color: var(--accent-yellow);
    text-decoration: underline;
}

.cart-btn, .favorites-btn, .notifications-btn {
    position: relative;
    font-size: 20px;
}

.cart-count, .favorites-count, .notifications-count {
    position: absolute;
    top: -6px;
    right: -6px;
    background: var(--error-red);
    color: var(--white);
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

/* Notifications Dropdown */
.notifications-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background: var(--white);
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    width: 300px;
    max-height: 400px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.notifications-dropdown.active {
    display: block;
}

.notification-item {
    padding: 1rem;
    border-bottom: 1px solid var(--light-gray);
    cursor: pointer;
    transition: background 0.3s ease;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item:hover {
    background: var(--light-gray);
}

.notification-message {
    font-size: 0.9rem;
    color: var(--dark-gray);
    margin-bottom: 0.5rem;
}

.notification-time {
    font-size: 0.8rem;
    color: var(--text-gray);
}

.view-all-notifications {
    display: block;
    text-align: center;
    padding: 0.75rem;
    background: var(--primary-green);
    color: var(--white);
    text-decoration: none;
    font-weight: 500;
    border-radius: 0 0 8px 8px;
}

.view-all-notifications:hover {
    background: var(--secondary-green);
}

.scroll-to-top {
    position: fixed;
    bottom: 30px;
    left: 30px;
    width: 50px;
    height: 50px;
    background: var(--primary-green);
    color: var(--white);
    border: none;
    border-radius: 50%;
    font-size: 1.2rem;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(9, 27, 190, 0.3);
    transition: all 0.3s ease;
    z-index: 1001;
    opacity: 0;
    visibility: hidden;
    transform: translateY(20px);
}

.scroll-to-top.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.scroll-to-top:hover {
    background: var(--secondary-green);
    transform: translateY(-2px) scale(1.1);
    box-shadow: 0 6px 20px rgba(69, 145, 231, 0.4);
}

.scroll-to-top:active {
    transform: translateY(0) scale(0.95);
}

.floating-buttons {
    position: fixed;
    bottom: 20px;
    right: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    z-index: 1000;
    font-size: 2rem;
}

.floating-btn {
    background: var(--accent-yellow);
    color: var(--dark-gray);
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    border: none;
    cursor: pointer;
    transition: background 0.3s ease, transform 0.2s ease;
    position: relative;
    text-decoration: none;
}

.floating-btn:hover {
    background: var(--secondary-green);
    color: var(--white);
    transform: scale(1.1);
}

.floating-btn::after {
    content: attr(data-tooltip);
    position: absolute;
    right: 50px;
    top: 50%;
    transform: translateY(-50%);
    background: var(--dark-gray);
    color: var(--white);
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

.floating-btn:hover::after {
    opacity: 1;
    visibility: visible;
}

.floating-btn.chatbot-btn {
    color: var(--dark-gray);
}

.floating-btn.chatbot-btn:hover {
    color: var(--white);
}

.favorite-btn {
    background: none;
    border: none;
    color: red;
    font-size: 1.2rem;
    cursor: pointer;
    margin-top: 0.5rem;
    transition: color 0.3s ease, transform 0.2s ease;
}

.favorite-btn:hover {
    color: var(--secondary-green);
    transform: scale(1.2);
}

.favorite-btn.favorited {
    color: var(--error-red);
}

nav {
    margin-top: 0.5rem;
    background: var(--secondary-green);
    border-radius: 8px;
    padding: 0.5rem;
    padding-left: 6.7rem;
}

.nav-links {
    display: flex;
    justify-content: center;
    gap: 1rem;
    list-style: none;
    flex-wrap: wrap;
}

.nav-links a {
    color: var(--white);
    text-decoration: none;
    padding: 8px 15px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    transition: background 0.3s ease;
}

.nav-links a:hover, .nav-links a.active {
    background: var(--primary-green);
}

.bottom-bar {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 0.5rem;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
    z-index: 1000;
}

.bottom-bar-actions {
    display: flex;
    justify-content: space-around;
    align-items: center;
}

.bottom-bar-actions a,
.bottom-bar-actions button {
    color: var(--dark-gray);
    text-decoration: none;
    font-weight: 500;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    transition: color 0.3s ease, transform 0.2s ease;
    position: relative;
    border: none;
    background: none;
}

.bottom-bar-actions a:hover,
.bottom-bar-actions button:hover {
    color: var(--secondary-green);
    transform: scale(1.1);
}

.bottom-bar-actions a::after,
.bottom-bar-actions button::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 50px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--dark-gray);
    color: var(--white);
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

.bottom-bar-actions a:hover::after,
.bottom-bar-actions button:hover::after {
    opacity: 1;
    visibility: visible;
}

/* Enhanced Product Modal Styles */
.product-modal-content {
    max-width: 1200px;
    width: 95%;
    padding: 1.5rem;
    max-height: 90vh;
    overflow-y: auto;
    background: var(--white);
    border-radius: 12px;
    position: relative;
}

.product-modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    align-items: start;
}

.product-modal-left {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.product-modal-image {
    width: 100%;
    max-height: 400px;
    object-fit: contain;
    border-radius: 12px;
    background: var(--light-gray);
    padding: 1rem;
}

.product-modal-thumbnails {
    display: flex;
    gap: 0.5rem;
    overflow-x: auto;
    padding: 0.5rem 0;
}

.thumbnail {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: border-color 0.3s ease;
}

.thumbnail.active {
    border-color: var(--primary-green);
}

.product-modal-right {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.product-modal-header {
    border-bottom: 1px solid var(--light-gray);
    padding-bottom: 1rem;
}

.product-modal-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--primary-green);
    margin-bottom: 0.5rem;
}

.product-modal-price {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--secondary-green);
    margin-bottom: 0.5rem;
}

.product-modal-caption {
    color: var(--text-gray);
    line-height: 1.6;
}

.product-actions {
    display: flex;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.quantity-selector {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.quantity-input {
    width: 80px;
    padding: 8px;
    border: 1px solid var(--text-gray);
    border-radius: 6px;
    text-align: center;
    font-size: 1rem;
}

.action-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.action-btn.primary {
    background: var(--primary-green);
    color: var(--white);
}

.action-btn.secondary {
    background: var(--accent-yellow);
    color: var(--dark-gray);
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.action-btn.favorite-btn {
    background: none;
    padding: 8px 12px;
}

.action-btn.favorite-btn.favorited {
    color: red;
}

/* Enhanced Reviews Section */
.product-reviews {
    margin-top: 2rem;
}

.reviews-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.rating-summary {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.average-rating {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary-green);
}

.rating-stars {
    color: var(--accent-yellow);
    font-size: 1.2rem;
}

.review-form {
    margin-top: 1rem;
    padding: 1rem;
    background: var(--light-gray);
    border-radius: 8px;
}

.rating-input {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.star-rating {
    display: flex;
    gap: 0.25rem;
    cursor: pointer;
}

.star-rating .star {
    font-size: 1.5rem;
    color: var(--text-gray);
    transition: color 0.2s ease;
}

.star-rating .star:hover,
.star-rating .star.active {
    color: var(--accent-yellow);
}

.review-form textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--text-gray);
    border-radius: 8px;
    font-size: 0.9rem;
    resize: vertical;
    min-height: 80px;
    margin-bottom: 1rem;
}

.submit-review-btn {
    background: var(--primary-green);
    color: var(--white);
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background 0.3s ease;
}

.submit-review-btn:hover {
    background: var(--secondary-green);
}

.review-item {
    padding: 1rem;
    border: 1px solid var(--light-gray);
    border-radius: 8px;
    margin-bottom: 1rem;
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.reviewer-name {
    font-weight: 600;
    color: var(--dark-gray);
}

.review-date {
    color: var(--text-gray);
    font-size: 0.9rem;
}

.review-text {
    color: var(--text-gray);
    line-height: 1.5;
}

/* Enhanced Related Products */
.related-products-section {
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid var(--light-gray);
}

.related-products-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.view-all-related {
    color: var(--primary-green);
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
}

.view-all-related:hover {
    text-decoration: underline;
}

.related-products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.related-product-card {
    background: var(--white);
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transition: transform 0.3s ease;
    cursor: pointer;
    border: 1px solid var(--light-gray);
}

.related-product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
}

.related-product-image {
    width: 100%;
    height: 120px;
    object-fit: contain;
    margin-bottom: 0.5rem;
    border-radius: 6px;
}

.related-product-name {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--dark-gray);
}

.related-product-price {
    font-size: 0.8rem;
    color: var(--secondary-green);
    font-weight: 600;
}

.share-section {
    padding-top: 1rem;
    border-top: 1px solid var(--light-gray);
}

.share-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.share-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.3s ease;
    color: var(--white);
    font-size: 1rem;
}

.share-btn.whatsapp { background: #25D366; }
.share-btn.facebook { background: #1877F2; }
.share-btn.telegram { background: #0088cc; }
.share-btn.twitter { background: #1DA1F2; }

.share-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

/* Newsletter Section */
.newsletter-section {
    color: black;
    padding: 2rem 0;
    margin-top: 2rem;
}

.newsletter-content {
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
}

.newsletter-form {
    display: flex;
    gap: 1rem;
    margin-top: 1rem;
    flex-wrap: wrap;
    justify-content: center;
}

.newsletter-input {
    flex: 1;
    min-width: 250px;
    padding: 12px;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
}

.newsletter-btn {
    background: var(--accent-yellow);
    color: var(--dark-gray);
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s ease;
}

.newsletter-btn:hover {
    background: var(--secondary-green);
    color: var(--white);
}

/* FAQ Section */
.faq-section {
    background: var(--white);
    padding: 3rem 0;
}

.faq-container {
    max-width: 800px;
    margin: 0 auto;
}

.faq-item {
    border: 1px solid var(--light-gray);
    border-radius: 8px;
    margin-bottom: 1rem;
    overflow: hidden;
}

.faq-question {
    padding: 1.5rem;
    background: var(--light-gray);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
}

.faq-answer {
    padding: 1.5rem;
    display: none;
    color: var(--text-gray);
    line-height: 1.6;
}

.faq-item.active .faq-answer {
    display: block;
}

.feedback-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.feedback-form label {
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--dark-gray);
}

.feedback-form input,
.feedback-form textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--text-gray);
    border-radius: 8px;
    font-size: 0.9rem;
    color: var(--dark-gray);
}

.feedback-form textarea {
    resize: vertical;
    min-height: 100px;
}

.feedback-form button {
    background: var(--accent-yellow);
    color: var(--dark-gray);
    padding: 10px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s ease, color 0.3s ease;
}

.feedback-form button:hover {
    background: var(--secondary-green);
    color: var(--white);
}

.feedback-message {
    font-size: 0.9rem;
    text-align: center;
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.feedback-message.success {
    background: var(--success-green);
    color: var(--white);
}

.feedback-message.error {
    background: var(--error-red);
    color: var(--white);
}

/* Chatbot styles */
.chatbot-modal-content {
    max-width: 600px;
    width: 90%;
    padding: 1.5rem;
    text-align: left;
}

.chatbot-container {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-height: 60vh;
}

.chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    background: var(--light-gray);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.chatbot-message {
    padding: 0.75rem;
    border-radius: 8px;
    max-width: 80%;
    font-size: 0.9rem;
    line-height: 1.4;
}

.chatbot-message.bot {
    background: var(--primary-green);
    color: var(--white);
    align-self: flex-start;
}

.chatbot-message.user {
    background: var(--accent-yellow);
    color: var(--dark-gray);
    align-self: flex-end;
}

.chatbot-form {
    display: flex;
    gap: 0.5rem;
}

.chatbot-form input {
    flex: 1;
    padding: 10px;
    border: 1px solid var(--text-gray);
    border-radius: 8px;
    font-size: 0.9rem;
    color: var(--dark-gray);
}

.chatbot-form button {
    background: var(--accent-yellow);
    color: var(--dark-gray);
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s ease, color 0.3s ease;
}

.chatbot-form button:hover {
    background: var(--secondary-green);
    color: var(--white);
}

.chatbot-messages::-webkit-scrollbar {
    width: 8px;
}

.chatbot-messages::-webkit-scrollbar-track {
    background: var(--light-gray);
    border-radius: 8px;
}

.chatbot-messages::-webkit-scrollbar-thumb {
    background: var(--primary-green);
    border-radius: 8px;
}

.chatbot-messages::-webkit-scrollbar-thumb:hover {
    background: var(--secondary-green);
}

.hero {
    background: var(--primary-green);
    padding: 3rem 0;
    text-align: left;
    color: var(--white);
    position: relative;
}

.hero::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><path d="M0,10 Q25,0 50,10 T100,10" fill="none" stroke="%23ffffff" stroke-opacity=".1" stroke-width="2"/></svg>') repeat-x bottom;
}

.hero-content {
    position: relative;
    max-width: 600px;
}

.hero h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.8rem;
}

.hero p {
    font-size: 1rem;
    margin-bottom: 1.5rem;
    opacity: 0.9;
}

.cta-buttons {
    display: flex;
    gap: 0.8rem;
}

.cta-btn {
    padding: 12px 25px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
}

.cta-primary {
    background: var(--accent-yellow);
    color: var(--dark-gray);
}

.cta-secondary {
    background: transparent;
    color: var(--white);
    border: 1px solid var(--white);
}

.cta-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
}

.features {
    padding: 3rem 0;
    background: var(--white);
}

.features h2 {
    text-align: center;
    font-size: 2rem;
    font-weight: 600;
    margin-bottom: 2rem;
    color: var(--primary-green);
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.feature-card {
    background: var(--white);
    padding: 1.5rem;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    animation: fadeInUp 0.6s ease-out;
}

.feature-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
}

.feature-icon {
    width: 60px;
    height: 60px;
    background: var(--secondary-green);
    border-radius: 50%;
    margin: 0 auto 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--white);
}

.feature-card h3 {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--primary-green);
}

.feature-card p {
    color: var(--text-gray);
    font-size: 0.9rem;
}

.best-sellers {
    padding: 3rem 0;
    background: var(--light-gray);
}

.best-sellers h2 {
    text-align: center;
    font-size: 2rem;
    font-weight: 600;
    margin-bottom: 2rem;
    color: var(--primary-green);
}

.slider-container {
    position: relative;
    overflow: hidden;
    max-width: 100%;
    margin: 0 auto;
}

.slider-track {
    display: flex;
    transition: transform 0.5s ease-in-out;
    animation: slideInFromRight 1s ease-out forwards;
}

.slider-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0, 0, 0, 0.5);
    color: var(--white);
    border: none;
    padding: 10px 15px;
    cursor: pointer;
    font-size: 1.5rem;
    z-index: 10;
    transition: background 0.3s ease;
    border-radius: 50%;
}

.slider-arrow:hover {
    background: rgba(0, 0, 0, 0.8);
}

.slider-arrow.left {
    left: 10px;
}

.slider-arrow.right {
    right: 10px;
}

.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
}

.product-card {
    background: var(--white);
    padding: 1.5rem;
    border-radius: 10px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: 400px;
    width: 100%;
    max-width: 250px;
    margin: 0 auto;
    animation: fadeInUp 0.6s ease-out;
    flex-shrink: 0;
}

.product-card img {
    width: 170px;
    height: 170px;
    object-fit: cover;
    border-radius: 8px;
    margin-bottom: 0.75rem;
    background: var(--light-gray);
    cursor: pointer;
}

.product-card .caption {
    display: none;
}

.product-card h4 {
    font-size: 1rem;
    font-weight: bold;
    margin-bottom: 0.5rem;
    color: black;
}

.product-card .price {
    font-size: 0.9rem;
    color: var(--text-gray);
    margin-bottom: 0.5rem;
}

.product-card button, .product-card .login-link {
    border: none;
    padding: 8px 15px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1.1rem;
    margin-top: 0.5rem;
    text-decoration: none;
    display: inline-block;
    font-weight: 500;
    transition: background 0.3s ease, color 0.3s ease;
}

.product-card button:hover, .product-card .login-link:hover {
    background: var(--accent-yellow);
    color: var(--white);
}

.quantity-input {
    width: 60px;
    padding: 5px;
    margin: 0.5rem 0;
    border: none;
    border-radius: 4px;
    font-size: 0.9rem;
    text-align: center;
    margin-right: 1rem;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.no-results {
    text-align: center;
    font-size: 1.1rem;
    color: var(--text-gray);
    padding: 2rem;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.modal-content {
    background: var(--white);
    border-radius: 12px;
    padding: 2rem;
    max-width: 800px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    position: relative;
    animation: fadeIn 0.3s ease-out;
    text-align: center;
}

.modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-gray);
    cursor: pointer;
    z-index: 1;
}

.modal-close:hover {
    color: var(--error-red);
}

.modal h2 {
    font-size: 1.8rem;
    font-weight: 600;
    color: var(--primary-green);
    margin-bottom: 1.5rem;
}

.category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.category-item {
    background: var(--light-gray);
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
    transition: all 0.3s ease;
}

.category-item:hover {
    background: var(--secondary-green);
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
}

.category-item a {
    color: var(--dark-gray);
    text-decoration: none;
    font-size: 1rem;
    font-weight: 500;
}

.category-item:hover a {
    color: var(--white);
}

footer {
    background: var(--dark-gray);
    color: var(--white);
    padding: 2rem 0;
    margin-bottom: 0;
}

.footer-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.footer-section h3 {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.8rem;
    color: var(--accent-yellow);
}

.footer-section ul {
    list-style: none;
}

.footer-section ul li {
    margin-bottom: 0.5rem;
}

.footer-section ul li a {
    color: var(--text-gray);
    text-decoration: none;
    font-size: 0.9rem;
}

.footer-section ul li a:hover {
    color: var(--white);
}

.footer-bottom {
    border-top: 1px solid var(--text-gray);
    padding-top: 1rem;
    text-align: center;
    font-size: 0.9rem;
    color: var(--text-gray);
}

.fade-in {
    animation: fadeIn 0.6s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideInFromRight {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}

/* Responsive adjustments */
@media (max-width: 900px) {
    .scroll-to-top {
        bottom: 80px;
        left: 20px;
        width: 45px;
        height: 45px;
        font-size: 1.1rem;
    }
    
    .product-modal-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    
    .product-modal-image {
        max-height: 300px;
    }
    
    .notifications-dropdown {
        width: 280px;
        right: -50px;
    }
}

@media (max-width: 768px) {
    .container {
        max-width: 90%;
    }

    .header-top {
        justify-content: space-between;
    }

    .menu-icon {
        display: block;
    }

    .search-bar, .header-actions, nav {
        display: none;
    }

    .bottom-bar {
        display: block;
    }

    .floating-buttons {
        display: none;
    }

    .hero h1 {
        font-size: 1.8rem;
    }

    .hero p {
        font-size: 0.9rem;
    }

    .cta-buttons {
        flex-direction: column;
        align-items: flex-start;
    }

    .features-grid,
    .category-grid {
        grid-template-columns: 1fr;
    }

    .product-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
    }

    .product-card {
        max-width: 100%;
        height: 300px;
        padding: 0.5rem;
    }

    .product-card img {
        height: 150px;
    }

    .product-card h4 {
        font-size: 0.9rem;
    }

    .product-card .price {
        font-size: 0.8rem;
    }

    .product-card button, .product-card .login-link {
        padding: 6px 10px;
        font-size: 0.8rem;
    }

    .quantity-input {
        width: 50px;
        padding: 4px;
        font-size: 0.8rem;
    }

    .product-modal-content {
        width: 95%;
        padding: 1rem;
        margin: 1rem;
    }

    .product-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .action-btn {
        width: 100%;
        justify-content: center;
    }

    .related-products-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .newsletter-form {
        flex-direction: column;
    }

    .newsletter-input {
        min-width: auto;
    }

    .slider-arrow {
        padding: 8px 12px;
        font-size: 1.2rem;
    }

    .chatbot-modal-content {
        width: 95%;
        padding: 1rem;
    }

    .chatbot-messages {
        max-height: 50vh;
    }

    .chatbot-form input {
        font-size: 0.8rem;
    }

    .chatbot-form button {
        padding: 8px 15px;
        font-size: 0.8rem;
    }
    
    .notifications-dropdown {
        width: 250px;
        right: -30px;
    }
}

@media (min-width: 769px) {
    .menu-icon, .mobile-menu, .bottom-bar {
        display: none;
    }

    .search-bar, .header-actions, nav {
        display: flex;
    }
}

@media (max-width: 480px) {
    .container {
        max-width: 100%;
    }

    .logo {
        font-size: 1.2rem;
    }

    .logo-icon {
        width: 30px;
        height: 30px;
    }

    .product-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
    }

    .product-card {
        height: 280px;
        padding: 0.5rem;
    }

    .product-card img {
        height: 110px;
    }

    .product-card h4 {
        font-size: 0.9rem;
    }

    .product-card .price {
        font-size: 0.8rem;
    }

    .product-card button, .product-card .login-link {
        padding: 6px 10px;
        font-size: 0.8rem;
    }

    .quantity-input {
        width: 50px;
        padding: 4px;
        font-size: 0.8rem;
    }

    .hero h1 {
        font-size: 1.5rem;
    }

    .hero p {
        font-size: 0.8rem;
    }

    .bottom-bar-actions a, .bottom-bar-actions button {
        font-size: 1.2rem;
        width: 36px;
        height: 36px;
    }

    .product-modal-content {
        width: 98%;
        padding: 0.8rem;
        margin: 0.5rem;
    }

    .product-modal-title {
        font-size: 1.4rem;
    }

    .product-modal-price {
        font-size: 1.2rem;
    }

    .related-products-grid {
        grid-template-columns: 1fr;
    }

    .slider-arrow {
        padding: 6px 10px;
        font-size: 1rem;
    }

    .chatbot-modal-content {
        width: 95%;
        padding: 1rem;
    }

    .chatbot-messages {
        max-height: 50vh;
    }

    .chatbot-form input {
        font-size: 0.8rem;
    }

    .chatbot-form button {
        padding: 8px 15px;
        font-size: 0.8rem;
    }
    
    .notifications-dropdown {
        width: 220px;
        right: -20px;
    }
    
    .mobile-menu {
        width: 100%;
    }
}
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-top">
                <div class="logo">
                    <div class="logo-icon"><img style="height: 50px; width: 50px; border-radius:25px;" src="images/download.png" alt=""></div>
                    <span>Bugema CampusShop</span>
                </div>
                <button class="menu-icon">☰</button>
                <div class="search-bar">
                    <input type="text" class="search-input" placeholder="Search for Bags, Branded Jumpers, Pens...">
                    <button class="search-btn"><i class="fas fa-search"></i></button>
                    <div class="search-results"></div>
                </div>
                <div class="header-actions">
                    <?php if (isset($_SESSION['username'])): ?>
                        <span class="username"><a href="profile.php">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></a></span>
                        <a href="logout.php" class="header-btn"><i class="fas fa-sign-out-alt"></i></a>
                        <a href="favorites.php" class="header-btn favorites-btn">
                            <i class="fas fa-heart"></i>
                            <span class="favorites-count"><?php echo $favorites_count; ?></span>
                        </a>
                        <a href="cart.php" class="header-btn cart-btn">
                            <i class="fas fa-shopping-cart"></i>
                            <span class="cart-count"><?php echo $cart_count; ?></span>
                        </a>
                        <div class="notifications-wrapper" style="position: relative;">
                            <a href="profile.php?tab=notifications" class="header-btn notifications-btn">
                                <i class="fas fa-bell"></i>
                                <?php if ($notifications_count > 0): ?>
                                    <span class="notifications-count"><?php echo $notifications_count; ?></span>
                                <?php endif; ?>
                            </a>
                            <!-- Notifications Dropdown -->
                            <div class="notifications-dropdown">
                                <?php if (!empty($recent_notifications)): ?>
                                    <?php foreach ($recent_notifications as $notification): ?>
                                        <div class="notification-item">
                                            <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                            <div class="notification-time"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-item">
                                        <div class="notification-message">No new notifications</div>
                                    </div>
                                <?php endif; ?>
                                <a href="profile.php?tab=notifications" class="view-all-notifications">View All Notifications</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="login.php" class="header-btn"><i class="fas fa-sign-in-alt"></i></a>
                        <a href="favorites.php" class="header-btn favorites-btn">
                            <i class="fas fa-heart"></i>
                            <span class="favorites-count"><?php echo $favorites_count; ?></span>
                        </a>
                        <a href="cart.php" class="header-btn cart-btn">
                            <i class="fas fa-shopping-cart"></i>
                            <span class="cart-count"><?php echo $cart_count; ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <nav>
                <ul class="nav-links">
                    <li><a href="index.php" class="active">Home</a></li>
                    <li><a href="Bags.php">Bags</a></li>
                    <li><a href="Branded Jumpers.php">Branded Jumpers</a></li>
                    <li><a href="Pens.php">Pens</a></li>
                    <li><a href="Wall Clocks.php">Clocks</a></li>
                    <li><a href="Note Books.php">Note Books</a></li>
                    <li><a href="T-Shirts.php">T-Shirts</a></li>
                    <li><a href="Bottles.php">Bottles</a></li>
                    <li><a href="favorites.php">Favorites</a></li>
                </ul>
            </nav>
            <div class="mobile-menu">
                <div class="mobile-menu-header">
                    <div class="mobile-menu-title">Menu</div>
                    <button class="close-icon">✖</button>
                </div>
                <?php if (isset($_SESSION['username'])): ?>
                    <div class="mobile-username">
                        <a href="profile.php">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></a>
                    </div>
                <?php endif; ?>
                <div class="mobile-search-bar">
                    <input type="text" class="search-input" placeholder="Search for Bags, Branded Jumpers, Pens...">
                    <button class="search-btn"><i class="fas fa-search"></i></button>
                    <div class="search-results"></div>
                </div>
                <div class="mobile-nav">
                    <a href="index.php" class="active"> Home</a>
                    <a href="Bags.php"> Bags</a>
                    <a href="Branded Jumpers.php"> Branded Jumpers</a>
                    <a href="Pens.php"> Pens</a>
                    <a href="Wall Clocks.php"> Clocks</a>
                    <a href="Note Books.php"> Note Books</a>
                    <a href="T-Shirts.php"> T-Shirts</a>
                    <a href="Bottles.php"> Bottles</a>
                    <a href="favorites.php"> Favorites</a>
                    <a href="profile.php?tab=notifications"> Notifications</a>
                    <?php if (isset($_SESSION['username'])): ?>
                        <a href="logout.php"> Logout</a>
                    <?php else: ?>
                        <a href="login.php"> Login</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="bottom-bar">
        <div class="bottom-bar-actions">
            <?php if (isset($_SESSION['username'])): ?>
                <a href="profile.php" data-tooltip="Profile"><i class="fas fa-user"></i></a>
                <a href="favorites.php" data-tooltip="Favorites"><i class="fas fa-heart"></i> <span class="favorites-count"><?php echo $favorites_count; ?></span></a>
                <a href="cart.php" data-tooltip="Cart"><i class="fas fa-shopping-cart"></i> <span class="cart-count"><?php echo $cart_count; ?></span></a>
                <a href="profile.php?tab=notifications" data-tooltip="Notifications"><i class="fas fa-bell"></i> 
                    <?php if ($notifications_count > 0): ?>
                        <span class="notifications-count"><?php echo $notifications_count; ?></span>
                    <?php endif; ?>
                </a>
                <button class="feedback-btn" id="mobile-feedback-btn" data-tooltip="Feedback"><i class="fas fa-comments"></i></button>
                <a href="https://wa.me/+256755087665" target="_blank" data-tooltip="Help"><i class="fab fa-whatsapp"></i></a>
            <?php else: ?>
                <a href="login.php" data-tooltip="Login"><i class="fas fa-sign-in-alt"></i></a>
                <a href="favorites.php" data-tooltip="Favorites"><i class="fas fa-heart"></i> <span class="favorites-count"><?php echo $favorites_count; ?></span></a>
                <a href="cart.php" data-tooltip="Cart"><i class="fas fa-shopping-cart"></i> <span class="cart-count"><?php echo $cart_count; ?></span></a>
                <button class="feedback-btn" id="mobile-feedback-btn" data-tooltip="Feedback"><i class="fas fa-comments"></i></button>
                <a href="https://wa.me/+256755087665" target="_blank" data-tooltip="Help"><i class="fab fa-whatsapp"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <!-- SCROLL TO TOP BUTTON -->
    <button class="scroll-to-top" id="scrollToTop" title="Back to Top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div class="floating-buttons">
        <button class="floating-btn feedback-btn" id="floating-feedback-btn" data-tooltip="Feedback"><i class="fas fa-comments"></i></button>
        <button class="floating-btn chatbot-btn" id="floating-chatbot-btn" data-tooltip="Chat with Us"><i class="fas fa-robot"></i></button>
        <a href="https://wa.me/+256755087665" class="floating-btn" target="_blank" data-tooltip="Help"><i class="fab fa-whatsapp"></i></a>
    </div>

    <section class="hero">
        <div class="container">
            <div class="hero-content fade-in">
                <h1>Your Campus, Your Store</h1>
                <p>Everything you need for academic success and campus life at Bugema University</p>
                <div class="cta-buttons">
                    <a href="cart.php" class="cta-btn cta-primary">Shop Now</a>
                    <button class="cta-btn cta-secondary" id="browse-categories-btn">Browse Categories</button>
                </div>
            </div>
        </div>
    </section>

    <div class="modal" id="categories-modal">
        <div class="modal-content">
            <button class="modal-close" id="modal-close-btn">&times;</button>
            <h2>Browse Categories</h2>
            <div class="category-grid">
                <?php
                $categories = ['Bags', 'Bottles', 'Branded Jumpers', 'Wall Clocks', 'Note Books', 'T-Shirts', 'Pens'];
                foreach ($categories as $category):
                    $category_link = str_replace(' ', '%20', $category) . '.php';
                    ?>
                    <div class="category-item">
                        <a href="<?php echo $category_link; ?>"><?php echo htmlspecialchars($category); ?></a>
                    </div>
                    <?php
                endforeach;
                ?>
            </div>
        </div>
    </div>

    <div class="modal" id="feedback-modal">
        <div class="modal-content">
            <button class="modal-close" id="feedback-modal-close">&times;</button>
            <h2>Leave Your Feedback</h2>
            <div class="feedback-message" id="feedback-message" style="display: none;"></div>
            <form id="feedback-form" class="feedback-form">
                <label for="feedback_name">Name</label>
                <input type="text" id="feedback_name" name="feedback_name" value="<?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : ''; ?>" required>
                <label for="feedback_email">Email</label>
                <input type="email" id="feedback_email" name="feedback_email" placeholder="Enter your email" required>
                <label for="feedback_message">Message</label>
                <textarea id="feedback_message" name="feedback_message" required></textarea>
                <button type="submit" name="submit_feedback">Submit Feedback</button>
            </form>
        </div>
    </div>

    <div class="modal" id="chatbot-modal" role="dialog" aria-labelledby="chatbot-title" aria-modal="true">
        <div class="modal-content chatbot-modal-content">
            <button class="modal-close" id="chatbot-modal-close" aria-label="Close chatbot">&times;</button>
            <h2 id="chatbot-title">CampusShop Assistant</h2>
            <div class="chatbot-container">
                <div class="chatbot-messages" id="chatbot-messages">
                    <div class="chatbot-message bot">
                        <p>Hello! Welcome to Bugema CampusShop's Assistant. How can I help you today? Try asking about products, delivery, or discounts!</p>
                    </div>
                </div>
                <form id="chatbot-form" class="chatbot-form">
                    <label for="chatbot-input" class="sr-only">Type your question</label>
                    <input type="text" id="chatbot-input" placeholder="Type your question..." autocomplete="off" required aria-required="true">
                    <button type="submit" aria-label="Send message">Send</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Product Modal -->
    <div class="modal" id="product-modal">
        <div class="modal-content product-modal-content">
            <button class="modal-close" id="product-modal-close" aria-label="Close product modal">&times;</button>
            <div class="product-modal-grid">
                <div class="product-modal-left">
                    <img id="product-modal-image" src="" alt="Product Image" class="product-modal-image" loading="lazy">
                    <div class="product-modal-thumbnails" id="product-thumbnails">
                        <!-- Thumbnails will be populated by JavaScript -->
                    </div>
                    <br>
                    <div class="reviews-list" id="reviews-list">
                        <!-- Reviews will be populated by JavaScript -->
                    </div>
                </div>
                <div class="product-modal-right">
                    <div class="product-modal-header">
                        <h3 id="product-modal-title" class="product-modal-title"></h3>
                        <div id="product-modal-price" class="product-modal-price"></div>
                        <p id="product-modal-caption" class="product-modal-caption"></p>
                    </div>
                    
                    <div class="product-actions">
                        <div class="quantity-selector">
                            <label for="product-quantity">Quantity:</label>
                            <input type="number" name="quantity" id="product-quantity" class="quantity-input" value="1" min="1" aria-label="Quantity">
                        </div>
                        <button type="button" class="action-btn primary" id="product-add-to-cart">
                            <i class="fas fa-shopping-cart"></i>
                        </button>
                        <button type="button" class="action-btn favorite-btn" id="product-toggle-favorite">
                            <i class="fas fa-heart"></i>
                        </button>
                    </div>
                    <br><br><br><br><br><br>
                    <div class="product-reviews">
                        <div class="reviews-header">
                            <h4>Customer Reviews</h4>
                            <div class="rating-summary">
                                <div class="average-rating" id="average-rating">4.5</div>
                                <div class="rating-stars">
                                    <i class="fas fa-star"></i>
                                    <i class="fas fa-star"></i>
                                    <i class="fas fa-star"></i>
                                    <i class="fas fa-star"></i>
                                    <i class="fas fa-star-half-alt"></i>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Review Form -->
                        <div class="review-form">
                            <h5>Add Your Review</h5>
                            <div class="rating-input">
                                <label>Your Rating:</label>
                                <div class="star-rating" id="star-rating">
                                    <span class="star" data-rating="1">★</span>
                                    <span class="star" data-rating="2">★</span>
                                    <span class="star" data-rating="3">★</span>
                                    <span class="star" data-rating="4">★</span>
                                    <span class="star" data-rating="5">★</span>
                                </div>
                                <span id="rating-text">Click to rate</span>
                            </div>
                            <textarea id="review-comment" placeholder="Share your experience with this product..."></textarea>
                            <button type="button" class="submit-review-btn" id="submit-review">Submit Review</button>
                        </div>
                    </div>

                    <div class="share-section">
                        <h5>Share This Product</h5>
                        <div class="share-buttons">
                            <a href="#" class="share-btn whatsapp" data-platform="whatsapp" target="_blank" aria-label="Share on WhatsApp"><i class="fab fa-whatsapp"></i></a>
                            <a href="#" class="share-btn facebook" data-platform="facebook" target="_blank" aria-label="Share on Facebook"><i class="fab fa-facebook"></i></a>
                            <a href="#" class="share-btn telegram" data-platform="telegram" target="_blank" aria-label="Share on Telegram"><i class="fab fa-telegram"></i></a>
                            <a href="#" class="share-btn twitter" data-platform="twitter" target="_blank" aria-label="Share on Twitter"><i class="fab fa-twitter"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="related-products-section">
                <div class="related-products-header">
                    <h4>Related Products</h4>
                    <a href="#" class="view-all-related">View All</a>
                </div>
                <div class="related-products-grid" id="related-products">
                    <!-- Related products will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <section class="features">
        <div class="container">
            <h2>Why Choose CampusShop?</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-truck"></i></div>
                    <h3>Fast Campus Delivery</h3>
                    <p>Get your orders delivered right to your dorm or pickup point within 24 hours</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-percent"></i></div>
                    <h3>Student Discounts</h3>
                    <p>Exclusive discounts for Bugema University students with valid student ID</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-book"></i></div>
                    <h3>Academic Resources</h3>
                    <p>All your Bags, Branded Jumpers, and study materials in one convenient place</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-graduation-cap"></i></div>
                    <h3>Campus Exclusive</h3>
                    <p>Official Bugema University Pens and branded items</p>
                </div>
            </div>
        </div>
    </section>

    <section class="best-sellers">
        <div class="container">
            <h2>Best & Most Sells</h2>
            <div class="slider-container">
                <button class="slider-arrow left"><i class="fas fa-chevron-left"></i></button>
                <div class="slider-track">
                    <?php
                    $stmt = $conn->prepare("SELECT * FROM products ORDER BY RAND() LIMIT 10");
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $product_id = $row['id'];
                            $is_favorited = false;
                            if (isset($_SESSION['user_id'])) {
                                $user_id = $_SESSION['user_id'];
                                $fav_stmt = $conn->prepare("SELECT * FROM favorites WHERE user_id = ? AND product_id = ?");
                                $fav_stmt->bind_param("ii", $user_id, $product_id);
                                $fav_stmt->execute();
                                $fav_result = $fav_stmt->get_result();
                                $is_favorited = $fav_result->num_rows > 0;
                                $fav_stmt->close();
                            } else {
                                $is_favorited = in_array($product_id, $_SESSION['guest_favorites']);
                            }
                            $image_path = !empty($row['image_path']) ? htmlspecialchars($row['image_path']) : 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+A8AAQAB3gB4cAAAAABJRU5ErkJggg==';
                            ?>
                            <div class="product-card">
                                <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($row['name']); ?>" 
                                     data-product-id="<?php echo $product_id; ?>" 
                                     data-title="<?php echo htmlspecialchars($row['name']); ?>" 
                                     data-caption="<?php echo htmlspecialchars($row['caption'] ?? 'No description available'); ?>" 
                                     data-price="UGX <?php echo number_format($row['price']); ?>" 
                                     data-favorited="<?php echo $is_favorited ? 'true' : 'false'; ?>"
                                     data-category="<?php echo htmlspecialchars($row['category'] ?? 'general'); ?>"
                                     class="product-image">
                                <h4><?php echo htmlspecialchars($row['name']); ?></h4>
                                <p class="caption"><?php echo htmlspecialchars($row['caption'] ?? 'No description available'); ?></p>
                                <p class="price">Price: UGX <?php echo number_format($row['price']); ?></p>
                                <form method="POST" action="index.php">
                                    <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                                    <input type="number" name="quantity" class="quantity-input" value="1" min="1">
                                    <button type="submit" name="add_to_cart"><i class="fas fa-shopping-cart"></i></button>
                                    <button type="submit" name="toggle_favorite" class="favorite-btn <?php echo $is_favorited ? 'favorited' : ''; ?>"><i class="fas fa-heart"></i></button>
                                </form>
                            </div>
                            <?php
                        }
                    } else {
                        echo "<div class='no-results'>No best sellers available</div>";
                    }
                    $stmt->close();
                    ?>
                </div>
                <button class="slider-arrow right"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="faq-section">
        <div class="container">
            <h2>Frequently Asked Questions</h2>
            <div class="faq-container">
                <div class="faq-item">
                    <div class="faq-question">
                        How long does delivery take?
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        We offer fast campus delivery within 24 hours to your dorm or campus pickup point. Orders placed before 2 PM are delivered the same day.
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        Do you offer student discounts?
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        Yes! Bugema University students with a valid student ID can enjoy exclusive discounts. Verify your ID at checkout to apply the discount.
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        What payment methods do you accept?
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        We accept mobile money (MTN, Airtel), cash on delivery, and credit/debit cards for online orders.
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        Can I return or exchange products?
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        Yes, we have a 7-day return policy for unused products in original packaging. Bags must be returned within 3 days of purchase.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Newsletter Section -->
    <section class="newsletter-section">
        <div class="container">
            <div class="newsletter-content">
                <h3>Stay Updated with CampusShop</h3>
                <p>Subscribe to our newsletter for exclusive deals, new arrivals, and campus events</p>
                <form id="newsletter-form" class="newsletter-form">
                    <input type="email" name="newsletter_email" class="newsletter-input" placeholder="Enter your email address" required>
                    <button type="submit" name="subscribe_newsletter" class="newsletter-btn">Subscribe</button>
                </form>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Bugema CampusShop</h3>
                    <p>Your official Bugema University online store, serving students with quality products and exceptional service.</p>
                </div>
                <div class="footer-section">
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="https://wa.me/+256755087665" target="_blank">Contact</a></li>
                        <li><a href="#faq">FAQs</a></li>
                        <li><a href="Bottles.php">Bottles</a></li>
                        <li><a href="favorites.php">Favorites</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Connect</h3>
                    <ul>
                        <li><a href="#"><i class="fas fa-envelope"></i> campusshop@bugemauniv.ac.ug</a></li>
                        <li><a href="https://wa.me/+256755087665" target="_blank"><i class="fas fa-phone"></i> +256 7550 87665</a></li>
                        <li><a href="#"><i class="fas fa-map-marker-alt"></i> Bugema University</a></li>
                        <li><a href="#"><i class="fas fa-clock"></i> Mon-Fri 8AM-6PM</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 Bugema CampusShop - Bugema University. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // SCROLL TO TOP FUNCTIONALITY
        const scrollToTopBtn = document.getElementById('scrollToTop');
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                scrollToTopBtn.classList.add('show');
            } else {
                scrollToTopBtn.classList.remove('show');
            }
        });

        scrollToTopBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Mobile Menu Functionality - FIXED
        const menuIcon = document.querySelector('.menu-icon');
        const mobileMenu = document.querySelector('.mobile-menu');
        const closeIcon = document.querySelector('.close-icon');
        if (menuIcon && mobileMenu && closeIcon) {
            menuIcon.addEventListener('click', function() {
                mobileMenu.classList.add('active');
                document.body.style.overflow = 'hidden';
            });

            closeIcon.addEventListener('click', function() {
                mobileMenu.classList.remove('active');
                document.body.style.overflow = '';
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!mobileMenu.contains(e.target) && !menuIcon.contains(e.target) && mobileMenu.classList.contains('active')) {
                    mobileMenu.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });

            // Close mobile menu with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
                    mobileMenu.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        }

        // Notifications Dropdown
        const notificationsBtn = document.querySelector('.notifications-btn');
        const notificationsDropdown = document.querySelector('.notifications-dropdown');
        
        if (notificationsBtn && notificationsDropdown) {
            notificationsBtn.addEventListener('click', function(e) {
                e.preventDefault();
                notificationsDropdown.classList.toggle('active');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationsBtn.contains(e.target) && !notificationsDropdown.contains(e.target)) {
                    notificationsDropdown.classList.remove('active');
                }
            });
        }

        // FAQ functionality
        document.querySelectorAll('.faq-question').forEach(question => {
            question.addEventListener('click', function() {
                const faqItem = this.parentElement;
                faqItem.classList.toggle('active');
                
                const icon = this.querySelector('i');
                if (faqItem.classList.contains('active')) {
                    icon.className = 'fas fa-chevron-up';
                } else {
                    icon.className = 'fas fa-chevron-down';
                }
            });
        });

        // Newsletter subscription
        const newsletterForm = document.getElementById('newsletter-form');
        if (newsletterForm) {
            newsletterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    alert(data.message);
                    if (data.success) {
                        newsletterForm.reset();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            });
        }

        // FIXED FEEDBACK FUNCTIONALITY
        const feedbackBtn = document.getElementById('floating-feedback-btn');
        const mobileFeedbackBtn = document.getElementById('mobile-feedback-btn');
        const feedbackModal = document.getElementById('feedback-modal');
        const feedbackModalClose = document.getElementById('feedback-modal-close');
        const feedbackForm = document.getElementById('feedback-form');
        const feedbackMessage = document.getElementById('feedback-message');

        // Feedback Modal Open
        if (feedbackBtn) {
            feedbackBtn.addEventListener('click', () => {
                feedbackModal.style.display = 'flex';
                feedbackMessage.style.display = 'none';
            });
        }

        if (mobileFeedbackBtn) {
            mobileFeedbackBtn.addEventListener('click', () => {
                feedbackModal.style.display = 'flex';
                feedbackMessage.style.display = 'none';
            });
        }

        // Close Feedback Modal
        if (feedbackModalClose) {
            feedbackModalClose.addEventListener('click', () => {
                feedbackModal.style.display = 'none';
                feedbackForm.reset();
                feedbackMessage.style.display = 'none';
            });
        }

        feedbackModal.addEventListener('click', (e) => {
            if (e.target === feedbackModal) {
                feedbackModal.style.display = 'none';
                feedbackForm.reset();
                feedbackMessage.style.display = 'none';
            }
        });

        // FIXED FEEDBACK FORM SUBMISSION
        feedbackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(feedbackForm);
            formData.append('submit_feedback', 'true');

            fetch('index.php', {  // FIXED: Submit to current page
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                feedbackMessage.style.display = 'block';
                feedbackMessage.className = `feedback-message ${data.success ? 'success' : 'error'}`;
                feedbackMessage.textContent = data.message;
                if (data.success) {
                    feedbackForm.reset();
                    setTimeout(() => {
                        feedbackModal.style.display = 'none';
                        feedbackMessage.style.display = 'none';
                    }, 2000);
                }
            })
            .catch(error => {
                console.error('Error details:', error);
                feedbackMessage.style.display = 'block';
                feedbackMessage.className = 'feedback-message error';
                feedbackMessage.textContent = 'An error occurred. Please try again.';
            });
        });

        // FIXED CHATBOT FUNCTIONALITY
        const chatbotBtn = document.getElementById('floating-chatbot-btn');
        const chatbotModal = document.getElementById('chatbot-modal');
        const chatbotModalClose = document.getElementById('chatbot-modal-close');
        const chatbotForm = document.getElementById('chatbot-form');
        const chatbotMessages = document.getElementById('chatbot-messages');
        const chatbotInput = document.getElementById('chatbot-input');

        function openChatbotModal() {
            chatbotModal.style.display = 'flex';
            chatbotInput.focus();
            scrollToBottom();
        }

        function closeChatbotModal() {
            chatbotModal.style.display = 'none';
            chatbotInput.value = '';
        }

        function scrollToBottom() {
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }

        function addMessage(content, sender) {
            const messageDiv = document.createElement('div');
            messageDiv.classList.add('chatbot-message', sender);
            messageDiv.innerHTML = `<p>${content}</p>`;
            chatbotMessages.appendChild(messageDiv);
            scrollToBottom();
        }

        const responses = {
            'hello': 'Hi! How can I assist you today?',
            'delivery': 'We offer fast campus delivery within 24 hours to your dorm or a campus pickup point. Would you like more details on delivery options?',
            'discount': 'Bugema University students with a valid student ID can enjoy exclusive discounts. Verify your ID at checkout to apply them!',
            'products': 'We offer Bags, branded jumpers, pens, clocks, notebooks, T-shirts, and bottles. Browse categories via the "Browse Categories" button!',
            'contact': 'You can reach us at campusshop@bugemauniv.ac.ug or via WhatsApp at +256 7550 87665. Want to call now?',
            'help': 'I\'m here to assist! Ask about delivery, discounts, products, or anything else.',
            'default': 'Sorry, I didn\'t understand that. Try asking about delivery, discounts, products, or contact info!'
        };

        // Chatbot event listeners
        if (chatbotBtn) {
            chatbotBtn.addEventListener('click', openChatbotModal);
        }

        if (chatbotModalClose) {
            chatbotModalClose.addEventListener('click', closeChatbotModal);
        }

        chatbotModal.addEventListener('click', function(e) {
            if (e.target === chatbotModal) {
                closeChatbotModal();
            }
        });

        chatbotForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const message = chatbotInput.value.trim();
            if (!message) {
                addMessage('Please enter a message.', 'bot');
                return;
            }

            // Add user message
            addMessage(message, 'user');

            // Get bot response
            const lowerMessage = message.toLowerCase();
            let response = responses['default'];
            for (const key in responses) {
                if (lowerMessage.includes(key)) {
                    response = responses[key];
                    break;
                }
            }

            // Add bot response
            setTimeout(() => {
                addMessage(response, 'bot');
            }, 500);

            chatbotInput.value = '';
            chatbotInput.focus();
        });

        chatbotInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatbotForm.dispatchEvent(new Event('submit'));
            }
        });

        // Enhanced Product Modal functionality
        const productModal = document.getElementById('product-modal');
        const productModalClose = document.getElementById('product-modal-close');
        const productImages = document.querySelectorAll('.product-image');
        
        // Sample data for demonstration
        const sampleReviews = [
            { name: "John Student", rating: 5, date: "2024-01-15", comment: "Excellent quality! Fast delivery to my dorm." },
            { name: "Sarah Johnson", rating: 4, date: "2024-01-10", comment: "Good product, reasonable price for students." },
            { name: "Mike Davis", rating: 5, date: "2024-01-08", comment: "Perfect for campus life. Highly recommended!" }
        ];

        // Get all products for related products
        const allProducts = [];
        <?php
        $allProductsStmt = $conn->prepare("SELECT id, name, price, image_path, category FROM products");
        $allProductsStmt->execute();
        $allProductsResult = $allProductsStmt->get_result();
        while ($product = $allProductsResult->fetch_assoc()) {
            echo "allProducts.push(" . json_encode($product) . ");";
        }
        $allProductsStmt->close();
        ?>

        function openProductModal(productData) {
            // Set main product details
            document.getElementById('product-modal-image').src = productData.image;
            document.getElementById('product-modal-title').textContent = productData.title;
            document.getElementById('product-modal-price').textContent = productData.price;
            document.getElementById('product-modal-caption').textContent = productData.caption;
            
            // Set thumbnails
            const thumbnailsContainer = document.getElementById('product-thumbnails');
            thumbnailsContainer.innerHTML = '';
            for (let i = 0; i < 3; i++) {
                const thumbnail = document.createElement('img');
                thumbnail.src = productData.image;
                thumbnail.alt = `Thumbnail ${i + 1}`;
                thumbnail.className = 'thumbnail' + (i === 0 ? ' active' : '');
                thumbnail.addEventListener('click', function() {
                    document.getElementById('product-modal-image').src = this.src;
                    document.querySelectorAll('.thumbnail').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                });
                thumbnailsContainer.appendChild(thumbnail);
            }
            
            // Set reviews
            const reviewsList = document.getElementById('reviews-list');
            reviewsList.innerHTML = '';
            sampleReviews.forEach(review => {
                const reviewItem = document.createElement('div');
                reviewItem.className = 'review-item';
                reviewItem.innerHTML = `
                    <div class="review-header">
                        <span class="reviewer-name">${review.name}</span>
                        <span class="review-date">${review.date}</span>
                    </div>
                    <div class="rating-stars">
                        ${'<i class="fas fa-star"></i>'.repeat(review.rating)}
                    </div>
                    <p class="review-text">${review.comment}</p>
                `;
                reviewsList.appendChild(reviewItem);
            });
            
            // Set related products based on category
            const relatedProductsContainer = document.getElementById('related-products');
            relatedProductsContainer.innerHTML = '';
            
            const currentCategory = productData.category || 'general';
            const relatedProducts = allProducts.filter(product => 
                product.category === currentCategory && product.id != productData.productId
            ).slice(0, 4);
            
            if (relatedProducts.length > 0) {
                relatedProducts.forEach(product => {
                    const productCard = document.createElement('div');
                    productCard.className = 'related-product-card';
                    productCard.innerHTML = `
                        <img src="${product.image_path || 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+A8AAQAB3gB4cAAAAABJRU5ErkJggg=='}" 
                             alt="${product.name}" class="related-product-image">
                        <div class="related-product-name">${product.name}</div>
                        <div class="related-product-price">UGX ${Number(product.price).toLocaleString()}</div>
                    `;
                    productCard.addEventListener('click', function() {
                        // Find and open the clicked product
                        const clickedProduct = allProducts.find(p => p.id == product.id);
                        if (clickedProduct) {
                            const productData = {
                                productId: clickedProduct.id,
                                image: clickedProduct.image_path || 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+A8AAQAB3gB4cAAAAABJRU5ErkJggg==',
                                title: clickedProduct.name,
                                caption: clickedProduct.caption || 'No description available',
                                price: `UGX ${Number(clickedProduct.price).toLocaleString()}`,
                                favorited: 'false',
                                category: clickedProduct.category || 'general'
                            };
                            openProductModal(productData);
                        }
                    });
                    relatedProductsContainer.appendChild(productCard);
                });
            } else {
                relatedProductsContainer.innerHTML = '<p>No related products found</p>';
            }
            
            // Set up action buttons
            const addToCartBtn = document.getElementById('product-add-to-cart');
            const favoriteBtn = document.getElementById('product-toggle-favorite');
            
            addToCartBtn.onclick = function() {
                const quantity = document.getElementById('product-quantity').value;
                // In real implementation, this would add to cart via AJAX
                const formData = new FormData();
                formData.append('product_id', productData.productId);
                formData.append('quantity', quantity);
                formData.append('add_to_cart', 'true');
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    alert(`Added ${quantity} ${productData.title} to cart!`);
                    // Refresh page to update cart count
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to add to cart. Please try again.');
                });
            };
            
            favoriteBtn.onclick = function() {
                const formData = new FormData();
                formData.append('product_id', productData.productId);
                formData.append('toggle_favorite', 'true');
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    favoriteBtn.classList.toggle('favorited');
                    if (favoriteBtn.classList.contains('favorited')) {
                        favoriteBtn.innerHTML = '<i class="fas fa-heart"></i> Favorited';
                    } else {
                        favoriteBtn.innerHTML = '<i class="fas fa-heart"></i> Favorite';
                    }
                    // Refresh page to update favorites count
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to update favorites. Please try again.');
                });
            };
            
            // Set initial favorite state
            if (productData.favorited === 'true') {
                favoriteBtn.classList.add('favorited');
                favoriteBtn.innerHTML = '<i class="fas fa-heart"></i> Favorited';
            }
            
            // Interactive Star Rating
            const stars = document.querySelectorAll('.star-rating .star');
            const ratingText = document.getElementById('rating-text');
            let currentRating = 0;
            
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    currentRating = parseInt(this.getAttribute('data-rating'));
                    updateStarRating(currentRating);
                    ratingText.textContent = `Rated ${currentRating} star${currentRating > 1 ? 's' : ''}`;
                });
                
                star.addEventListener('mouseover', function() {
                    const hoverRating = parseInt(this.getAttribute('data-rating'));
                    updateStarRating(hoverRating, true);
                });
                
                star.addEventListener('mouseout', function() {
                    updateStarRating(currentRating);
                });
            });
            
            function updateStarRating(rating, isHover = false) {
                stars.forEach((star, index) => {
                    if (index < rating) {
                        star.classList.add('active');
                    } else {
                        star.classList.remove('active');
                    }
                });
                
                if (!isHover && rating === 0) {
                    ratingText.textContent = 'Click to rate';
                }
            }
            
            // Submit Review
            const submitReviewBtn = document.getElementById('submit-review');
            const reviewComment = document.getElementById('review-comment');
            
            submitReviewBtn.addEventListener('click', function() {
                if (currentRating === 0) {
                    alert('Please select a rating');
                    return;
                }
                
                if (!reviewComment.value.trim()) {
                    alert('Please enter a review comment');
                    return;
                }
                
                // In real implementation, this would save to database
                const newReview = {
                    name: "<?php echo isset($_SESSION['username']) ? $_SESSION['username'] : 'Anonymous'; ?>",
                    rating: currentRating,
                    date: new Date().toISOString().split('T')[0],
                    comment: reviewComment.value
                };
                
                // Add new review to the list
                const reviewItem = document.createElement('div');
                reviewItem.className = 'review-item';
                reviewItem.innerHTML = `
                    <div class="review-header">
                        <span class="reviewer-name">${newReview.name}</span>
                        <span class="review-date">${newReview.date}</span>
                    </div>
                    <div class="rating-stars">
                        ${'<i class="fas fa-star"></i>'.repeat(newReview.rating)}
                    </div>
                    <p class="review-text">${newReview.comment}</p>
                `;
                reviewsList.insertBefore(reviewItem, reviewsList.firstChild);
                
                // Reset form
                currentRating = 0;
                updateStarRating(0);
                ratingText.textContent = 'Click to rate';
                reviewComment.value = '';
                
                alert('Review submitted successfully!');
            });

            // Set up share links
            const shareUrl = `${window.location.origin}/product.php?id=${productData.productId}`;
            const encodedUrl = encodeURIComponent(shareUrl);
            const encodedText = encodeURIComponent(`Check out "${productData.title}" from Bugema CampusShop: ${shareUrl}`);
            
            document.querySelectorAll('.share-btn').forEach(btn => {
                const platform = btn.getAttribute('data-platform');
                let shareLink = '';
                switch(platform) {
                    case 'whatsapp':
                        shareLink = `https://api.whatsapp.com/send?text=${encodedText}`;
                        break;
                    case 'facebook':
                        shareLink = `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
                        break;
                    case 'telegram':
                        shareLink = `https://t.me/share/url?url=${encodedUrl}&text=${encodedText}`;
                        break;
                    case 'twitter':
                        shareLink = `https://twitter.com/intent/tweet?text=${encodedText}`;
                        break;
                }
                btn.href = shareLink;
            });
            
            productModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        // Add click event to all product images
        productImages.forEach(img => {
            img.addEventListener('click', function() {
                const productData = {
                    productId: this.getAttribute('data-product-id'),
                    image: this.src,
                    title: this.getAttribute('data-title'),
                    caption: this.getAttribute('data-caption'),
                    price: this.getAttribute('data-price'),
                    favorited: this.getAttribute('data-favorited'),
                    category: this.getAttribute('data-category') || 'general'
                };
                openProductModal(productData);
            });
        });

        productModalClose.addEventListener('click', function() {
            productModal.style.display = 'none';
            document.body.style.overflow = '';
        });

        productModal.addEventListener('click', function(e) {
            if (e.target === productModal) {
                productModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });

        // Rest of your existing JavaScript functionality
        const searchInput = document.querySelectorAll('.search-input');
        const searchResults = document.querySelectorAll('.search-results');
        searchInput.forEach(input => {
            input.addEventListener('input', function() {
                // Your existing search implementation
            });
        });

        // Modal management
        const browseCategoriesBtn = document.getElementById('browse-categories-btn');
        const categoriesModal = document.getElementById('categories-modal');
        const modalCloseBtn = document.getElementById('modal-close-btn');
        browseCategoriesBtn.addEventListener('click', function() {
            categoriesModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        });

        modalCloseBtn.addEventListener('click', function() {
            categoriesModal.style.display = 'none';
            document.body.style.overflow = '';
        });

        categoriesModal.addEventListener('click', function(e) {
            if (e.target === categoriesModal) {
                categoriesModal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                });
                // Also close notifications dropdown
                if (notificationsDropdown) {
                    notificationsDropdown.classList.remove('active');
                }
                // Close mobile menu
                if (mobileMenu) {
                    mobileMenu.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // Slider functionality
        const sliderContainer = document.querySelector('.slider-container');
        if (sliderContainer) {
            const sliderTrack = sliderContainer.querySelector('.slider-track');
            const leftArrow = sliderContainer.querySelector('.left');
            const rightArrow = sliderContainer.querySelector('.right');
            const slides = sliderTrack.querySelectorAll('.product-card');
            let currentIndex = 0;
            let visibleSlides = getVisibleSlides();

            function getVisibleSlides() {
                return window.innerWidth <= 768 ? 2 : 4;
            }

            function updateSlider() {
                const slideWidth = slides[0].offsetWidth + parseInt(getComputedStyle(slides[0]).marginRight);
                sliderTrack.style.transform = `translateX(-${currentIndex * slideWidth}px)`;
            }

            leftArrow.addEventListener('click', () => {
                currentIndex = Math.max(currentIndex - 1, 0);
                updateSlider();
            });

            rightArrow.addEventListener('click', () => {
                currentIndex = Math.min(currentIndex + 1, slides.length - visibleSlides);
                updateSlider();
            });

            window.addEventListener('resize', () => {
                visibleSlides = getVisibleSlides();
                updateSlider();
            });

            // Add auto-sliding every 20 seconds
            setInterval(() => {
                currentIndex = (currentIndex + 1) % (slides.length - visibleSlides + 1);
                updateSlider();
            }, 20000);
        }

        // Add screen reader styles
        const style = document.createElement('style');
        style.textContent = `
            .sr-only {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                border: 0;
            }
        `;
        document.head.appendChild(style);
    });
    </script>
</body>
</html>

<?php
$conn->close();
?>