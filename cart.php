<?php
session_start();
include 'db_connect.php';

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
    $_SESSION['guest_favorites'] = [];
}

// Handle adding to cart
if (isset($_POST['add_to_cart'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity = isset($_POST['quantity']) ? trim($_POST['quantity']) : '';
    if (!ctype_digit($quantity) || (int)$quantity < 1) {
        $_SESSION['message'] = "Please enter a valid quantity (positive number).";
        header("Location: cart.php");
        exit();
    }
    $quantity = (int)$quantity;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
        $stmt->bind_param("iiii", $user_id, $product_id, $quantity, $quantity);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = "Item added to cart!";
    } else {
        if (!isset($_SESSION['guest_cart'][$product_id])) {
            $_SESSION['guest_cart'][$product_id] = $quantity;
        } else {
            $_SESSION['guest_cart'][$product_id] += $quantity;
        }
        $_SESSION['message'] = "Item added to cart! Please log in to checkout.";
    }
    header("Location: cart.php");
    exit();
}

// Handle removing from cart
if (isset($_POST['remove_from_cart'])) {
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $product_id = (int)$_POST['product_id'];
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = "Item removed from cart.";
    } else {
        $product_id = (int)$_POST['product_id'];
        if (isset($_SESSION['guest_cart'][$product_id])) {
            unset($_SESSION['guest_cart'][$product_id]);
            $_SESSION['message'] = "Item removed from cart. Please log in to checkout.";
        }
    }
    header("Location: cart.php");
    exit();
}

// Handle updating quantity
if (isset($_POST['update_quantity'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity = isset($_POST['quantity']) ? trim($_POST['quantity']) : '';
    if (!ctype_digit($quantity) || (int)$quantity < 1) {
        $_SESSION['message'] = "Please enter a valid quantity (positive number).";
        header("Location: cart.php");
        exit();
    }
    $quantity = (int)$quantity;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("iii", $quantity, $user_id, $product_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = "Quantity updated.";
    } else {
        if (isset($_SESSION['guest_cart'][$product_id])) {
            $_SESSION['guest_cart'][$product_id] = $quantity;
            $_SESSION['message'] = "Quantity updated. Please log in to checkout.";
        }
    }
    header("Location: cart.php");
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

    if (empty($name) || empty($email) || empty($message)) {
        $response = ['success' => false, 'message' => 'All fields are required.'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

// Get notifications count - with error handling for missing column
$notifications_count = 0;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // First, check if notifications table exists and has is_read column
    $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($table_check && $table_check->num_rows > 0) {
        // Check if is_read column exists
        $column_check = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
        if ($column_check && $column_check->num_rows > 0) {
            // Column exists, use the original query
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $notifications_count = $result->fetch_assoc()['count'];
            $stmt->close();
        } else {
            // Column doesn't exist, count all notifications for user
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $notifications_count = $result->fetch_assoc()['count'];
            $stmt->close();
        }
    }
    // If notifications table doesn't exist, count remains 0
}

// Set user_email to empty string
$user_email = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - Bugema CampusShop</title>
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
        }

        .mobile-menu {
            position: fixed;
            top: 0;
            right: -100%;
            width: 250px;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            padding: 2rem;
            z-index: 1100;
            transition: right 0.3s ease;
            overflow-y: auto;
        }

        .mobile-menu.active {
            right: 0;
        }

        .close-icon {
            font-size: 1.5rem;
            background: none;
            border: none;
            color: var(--dark-gray);
            cursor: pointer;
            position: absolute;
            top: 1rem;
            right: 1rem;
        }

        .mobile-nav {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 2rem;
        }

        .mobile-nav a {
            color: var(--dark-gray);
            text-decoration: none;
            font-size: 1rem;
            padding: 0.5rem;
            border-radius: 8px;
            transition: background 0.3s ease;
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

        /* SCROLL TO TOP BUTTON - ADDED */
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

        nav {
            margin-top: 0.5rem;
            background: var(--secondary-green);
            border-radius: 8px;
            padding: 0.5rem;
            padding-left: 6.5rem;
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
            background: rgba(255, 255, 255, 0.8);
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

        .bottom-bar-actions a, .bottom-bar-actions button {
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

        .bottom-bar-actions a:hover, .bottom-bar-actions button:hover {
            color: var(--secondary-green);
            transform: scale(1.1);
        }

        .bottom-bar-actions a::after, .bottom-bar-actions button::after {
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

        .bottom-bar-actions a:hover::after, .bottom-bar-actions button:hover::after {
            opacity: 1;
            visibility: visible;
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

        .cart-container {
            padding: 3rem 0;
            background: var(--light-gray);
        }

        h2 {
            font-size: 2rem;
            font-weight: 600;
            color: var(--primary-green);
            text-align: center;
            margin-bottom: 2rem;
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
        }

        .product-card img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            margin: 0 auto 0.75rem;
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
            color: var(--dark-gray);
        }

        .product-card .price, .product-card .subtotal {
            font-size: 0.9rem;
            color: var(--text-gray);
            margin-bottom: 0.5rem;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 0.5rem;
        }

        .quantity-control input {
            width: 60px;
            padding: 5px;
            border: none;
            border-radius: 4px;
            font-size: 0.9rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .quantity-control input:invalid {
            border: 1px solid var(--error-red);
            background: rgba(220, 38, 38, 0.1);
        }

        .quantity-control button {
            background: var(--accent-yellow);
            color: var(--dark-gray);
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.3s ease;
            height: 36px;
            line-height: 1;
        }

        .quantity-control button:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
        }

        .action-buttons button, .action-buttons a {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s ease, color 0.3s ease;
            text-decoration: none;
            text-align: center;
            height: 36px;
            line-height: 1;
            flex: 1;
        }

        .remove-btn {
            color: var(--error-red);
            border: none;
        }

        .remove-btn:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        .checkout-btn {
            color: var(--dark-gray);
        }

        .checkout-btn:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        .image-modal-content .quantity-input {
            width: 60px;
            padding: 5px;
            border: none;
            border-radius: 4px;
            font-size: 0.9rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            height: 36px;
        }

        .image-modal-content .update-btn,
        .image-modal-content .remove-btn,
        .image-modal-content .checkout-btn {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s ease, color 0.3s ease;
            text-decoration: none;
            text-align: center;
            height: 36px;
            line-height: 1;
            flex: 1;
        }

        .image-modal-content .update-btn {
            background: var(--accent-yellow);
            color: var(--dark-gray);
            border: none;
        }

        .image-modal-content .update-btn:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        .image-modal-content .remove-btn {
            background: var(--error-red);
            color: var(--white);
            border: none;
        }

        .image-modal-content .remove-btn:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        .image-modal-content .checkout-btn {
            background: var(--accent-yellow);
            color: var(--dark-gray);
        }

        .image-modal-content .checkout-btn:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        .modal-form {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .checkout-container {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            text-align: center;
            max-width: 400px;
            margin: 2rem auto;
        }

        .cart-total {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 1rem;
        }

        .main-checkout-btn {
            display: inline-block;
            background: var(--primary-green);
            color: var(--white);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.3s ease;
            width: 100%;
        }

        .main-checkout-btn:hover {
            background: var(--secondary-green);
        }

        .empty-cart {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            text-align: center;
            max-width: 400px;
            margin: 2rem auto;
        }

        .empty-cart p {
            font-size: 1rem;
            color: var(--text-gray);
            margin-bottom: 1rem;
        }

        .empty-cart a {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 500;
        }

        .empty-cart a:hover {
            text-decoration: underline;
            color: var(--secondary-green);
        }

        .message {
            background: var(--success-green);
            color: var(--white);
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            text-align: center;
            font-size: 0.9rem;
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
        }

        .image-modal-content {
            background: var(--white);
            border-radius: 12px;
            padding: 2.5rem;
            max-width: 90%;
            width: 600px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: fadeIn 0.3s ease-out;
            text-align: center;
        }

        .image-modal-content img {
            width: 300px;
            height: 300px;
            object-fit: cover;
            border-radius: 8px;
            margin: 0 auto 1rem;
        }

        .image-modal-content .caption {
            display: block;
            font-size: 1.1rem;
            color: var(--text-gray);
            margin-bottom: 0.75rem;
        }

        .image-modal-content .price {
            font-size: 1rem;
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
        }

        .modal-close:hover {
            color: var(--error-red);
        }

        .modal h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 1.5rem;
            text-align: center;
        }

        footer {
            background: var(--dark-gray);
            color: var(--white);
            padding: 2rem 0;
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

        /* Responsive adjustments for scroll-to-top */
        @media (max-width: 900px) {
            .scroll-to-top {
                bottom: 80px;
                left: 20px;
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
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
                width: 120px;
                height: 120px;
            }

            .product-card h4 {
                font-size: 0.9rem;
            }

            .product-card .price, .product-card .subtotal {
                font-size: 0.8rem;
            }

            .quantity-control input {
                width: 50px;
                padding: 4px;
                font-size: 0.8rem;
            }

            .quantity-control button, .action-buttons button, .action-buttons a {
                padding: 6px 10px;
                font-size: 0.8rem;
                height: 32px;
            }

            .image-modal-content {
                width: 95%;
                padding: 1rem;
            }

            .image-modal-content img {
                width: 200px;
                height: 200px;
            }

            .image-modal-content h4 {
                font-size: 1rem;
            }

            .image-modal-content .caption {
                font-size: 0.9rem;
            }

            .image-modal-content .price {
                font-size: 1rem;
            }

            .image-modal-content .quantity-input {
                width: 50px;
                font-size: 0.8rem;
                height: 32px;
            }

            .image-modal-content .update-btn,
            .image-modal-content .remove-btn,
            .image-modal-content .checkout-btn {
                padding: 6px 10px;
                font-size: 0.8rem;
                height: 32px;
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
                width: 100px;
                height: 100px;
            }

            .product-card h4 {
                font-size: 0.9rem;
            }

            .product-card .price, .product-card .subtotal {
                font-size: 0.8rem;
            }

            .quantity-control input {
                width: 50px;
                padding: 4px;
                font-size: 0.8rem;
            }

            .quantity-control button, .action-buttons button, .action-buttons a {
                padding: 6px 10px;
                font-size: 0.8rem;
                height: 32px;
            }

            .cart-total {
                font-size: 1.1rem;
            }

            .main-checkout-btn {
                font-size: 1rem;
                padding: 10px 20px;
            }

            .image-modal-content {
                width: 95%;
                padding: 1rem;
            }

            .image-modal-content img {
                width: 180px;
                height: 180px;
            }

            .image-modal-content h4 {
                font-size: 1rem;
            }

            .image-modal-content .caption {
                font-size: 0.9rem;
            }

            .image-modal-content .price {
                font-size: 1rem;
            }

            .image-modal-content .quantity-input {
                width: 50px;
                font-size: 0.8rem;
                height: 32px;
            }

            .image-modal-content .update-btn,
            .image-modal-content .remove-btn,
            .image-modal-content .checkout-btn {
                padding: 6px 10px;
                font-size: 0.8rem;
                height: 32px;
            }
            .bottom-bar-actions a, .bottom-bar-actions button {
                padding: 6px;
                font-size: 1.2rem;
                width: 36px;
                height: 36px;
            }
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
    </style>
</head>
<body>
    <!-- SCROLL TO TOP BUTTON - ADDED -->
    <button class="scroll-to-top" id="scrollToTop" title="Back to Top">
        <i class="fas fa-arrow-up"></i>
    </button>

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
                        <a href="cart.php" class="header-btn cart-btn active">
                            <i class="fas fa-shopping-cart"></i>
                            <span class="cart-count"><?php echo $cart_count; ?></span>
                        </a>
                        <a href="profile.php" class="header-btn notifications-btn">
                            <i class="fas fa-bell"></i>
                            <?php if ($notifications_count > 0): ?>
                                <span class="notifications-count"><?php echo $notifications_count; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="header-btn"><i class="fas fa-sign-in-alt"></i></a>
                        <a href="favorites.php" class="header-btn favorites-btn">
                            <i class="fas fa-heart"></i>
                            <span class="favorites-count"><?php echo $favorites_count; ?></span>
                        </a>
                        <a href="cart.php" class="header-btn cart-btn active">
                            <i class="fas fa-shopping-cart"></i>
                            <span class="cart-count"><?php echo array_sum($_SESSION['guest_cart']); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <nav>
                <ul class="nav-links">
                    <li><a href="index.php">Home</a></li>
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
                <button class="close-icon">✖</button>
                <?php if (isset($_SESSION['username'])): ?>
                    <span class="mobile-username"><a href="profile.php">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></a></span>
                <?php endif; ?>
                <div class="mobile-search-bar">
                    <input type="text" class="search-input" placeholder="Search for Bags, Branded Jumpers, Pens...">
                    <button class="search-btn"><i class="fas fa-search"></i></button>
                    <div class="search-results"></div>
                </div>
                <div class="mobile-nav">
                    <a href="index.php">Home</a>
                    <a href="Bags.php">Bags</a>
                    <a href="Branded Jumpers.php">Branded Jumpers</a>
                    <a href="Pens.php">Pens</a>
                    <a href="Wall Clocks.php">Clocks</a>
                    <a href="Note Books.php">Note Books</a>
                    <a href="T-Shirts.php">T-Shirts</a>
                    <a href="Bottles.php">Bottles</a>
                    <a href="favorites.php">Favorites</a>
                    <?php if (isset($_SESSION['username'])): ?>
                        <a href="logout.php">Logout</a>
                    <?php else: ?>
                        <a href="login.php">Login</a>
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
                <a href="profile.php" data-tooltip="Notifications"><i class="fas fa-bell"></i> 
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

    <div class="floating-buttons">
        <button class="floating-btn feedback-btn" id="floating-feedback-btn" data-tooltip="Feedback"><i class="fas fa-comments"></i></button>
        <button class="floating-btn chatbot-btn" id="floating-chatbot-btn" data-tooltip="Chat with Us"><i class="fas fa-robot"></i></button>
        <a href="https://wa.me/+256755087665" class="floating-btn" target="_blank" data-tooltip="Help"><i class="fab fa-whatsapp"></i></a>
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

    <div class="modal" id="image-modal">
        <div class="modal-content image-modal-content">
            <button class="modal-close" id="image-modal-close">&times;</button>
            <img id="modal-image" src="" alt="Product Image">
            <h4 id="modal-title"></h4>
            <p class="caption" id="modal-caption"></p>
            <p class="price" id="modal-price"></p>
            <p class="subtotal" id="modal-subtotal"></p>
            <form method="POST" action="cart.php" id="modal-form" class="modal-form">
                <input type="hidden" name="product_id" id="modal-product-id">
                <input type="number" name="quantity" class="quantity-input" id="modal-quantity" min="1">
                <button type="submit" name="update_quantity" class="update-btn">Update</button>
                <button type="submit" name="remove_from_cart" class="remove-btn">Remove</button>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="payment.php?cart_id=" id="modal-checkout-btn" class="checkout-btn">Checkout</a>
                <?php else: ?>
                    <a href="login.php?redirect=payment.php" class="checkout-btn">Login to Checkout</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <section class="cart-container">
        <div class="container">
            <h2>Your Cart</h2>
            <?php if (isset($_SESSION['message'])): ?>
                <div class="message"><?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?></div>
            <?php endif; ?>
            <?php
            $total = 0;
            if (isset($_SESSION['user_id'])) {
                $user_id = $_SESSION['user_id'];
                $stmt = $conn->prepare("SELECT c.id AS cart_id, p.id, p.name, p.price, p.image_path, p.caption, c.quantity FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    echo "<div class='product-grid'>";
                    while ($row = $result->fetch_assoc()) {
                        $image_path = !empty($row['image_path']) ? htmlspecialchars($row['image_path']) : 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+A8AAQAB3gB4cAAAAABJRU5ErkJggg==';
                        $subtotal = $row['price'] * $row['quantity'];
                        $total += $subtotal;
                        echo "<div class='product-card'>";
                        echo "<img src='$image_path' alt='" . htmlspecialchars($row['name']) . "' 
                                    data-product-id='" . $row['id'] . "' 
                                    data-cart-id='" . $row['cart_id'] . "' 
                                    data-title='" . htmlspecialchars($row['name']) . "' 
                                    data-caption='" . htmlspecialchars($row['caption'] ?? 'No description available') . "' 
                                    data-price='UGX " . number_format($row['price']) . "' 
                                    data-subtotal='UGX " . number_format($subtotal) . "'>";
                        echo "<h4>" . htmlspecialchars($row['name']) . "</h4>";
                        echo "<p class='caption'>" . htmlspecialchars($row['caption'] ?? 'No description available') . "</p>";
                        echo "<p class='price'>Price: UGX " . number_format($row['price']) . "</p>";
                        echo "<p class='subtotal'>Subtotal: UGX " . number_format($subtotal) . "</p>";
                        echo "<form method='POST' class='quantity-control'>";
                        echo "<input type='hidden' name='product_id' value='" . $row['id'] . "'>";
                        echo "<input type='number' name='quantity' value='" . $row['quantity'] . "' min='1' aria-label='Quantity' required>";
                        echo "<button type='submit' name='update_quantity' class='update-btn'>Update</button>";
                        echo "</form>";
                        echo "<div class='action-buttons'>";
                        echo "<form method='POST'>";
                        echo "<input type='hidden' name='product_id' value='" . $row['id'] . "'>";
                        echo "<button type='submit' name='remove_from_cart' class='remove-btn' aria-label='Remove item'>Remove</button>";
                        echo "<a href='payment.php?cart_id=" . $row['cart_id'] . "' class='checkout-btn' aria-label='Checkout this item'>Checkout</a>";
                        echo "</form>";
                        echo "</div>";
                        echo "</div>";
                    }
                    echo "</div>";
                    echo "<div class='checkout-container'>";
                    echo "<div class='cart-total'>Total: UGX " . number_format($total) . "</div>";
                    echo "<a href='payment.php' class='main-checkout-btn' aria-label='Proceed to checkout all items'>Proceed to Checkout</a>";
                    echo "</div>";
                } else {
                    echo "<div class='empty-cart'>";
                    echo "<p>Your cart is empty. <a href='index.php'>Continue shopping</a></p>";
                    echo "</div>";
                }
                $stmt->close();
            } else {
                if (!empty($_SESSION['guest_cart'])) {
                    $product_ids = array_keys($_SESSION['guest_cart']);
                    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
                    $stmt = $conn->prepare("SELECT id, name, price, image_path, caption FROM products WHERE id IN ($placeholders)");
                    $stmt->bind_param(str_repeat('i', count($product_ids)), ...$product_ids);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        echo "<div class='product-grid'>";
                        while ($row = $result->fetch_assoc()) {
                            $quantity = $_SESSION['guest_cart'][$row['id']];
                            $image_path = !empty($row['image_path']) ? htmlspecialchars($row['image_path']) : 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+A8AAQAB3gB4cAAAAABJRU5ErkJggg==';
                            $subtotal = $row['price'] * $quantity;
                            $total += $subtotal;
                            echo "<div class='product-card'>";
                            echo "<img src='$image_path' alt='" . htmlspecialchars($row['name']) . "' 
                                        data-product-id='" . $row['id'] . "' 
                                        data-title='" . htmlspecialchars($row['name']) . "' 
                                        data-caption='" . htmlspecialchars($row['caption'] ?? 'No description available') . "' 
                                        data-price='UGX " . number_format($row['price']) . "' 
                                        data-subtotal='UGX " . number_format($subtotal) . "'>";
                            echo "<h4>" . htmlspecialchars($row['name']) . "</h4>";
                            echo "<p class='caption'>" . htmlspecialchars($row['caption'] ?? 'No description available') . "</p>";
                            echo "<p class='price'>Price: UGX " . number_format($row['price']) . "</p>";
                            echo "<p class='subtotal'>Subtotal: UGX " . number_format($subtotal) . "</p>";
                            echo "<form method='POST' class='quantity-control'>";
                            echo "<input type='hidden' name='product_id' value='" . $row['id'] . "'>";
                            echo "<input type='number' name='quantity' value='$quantity' min='1' aria-label='Quantity' required>";
                            echo "<button type='submit' name='update_quantity' class='update-btn'>Update</button>";
                            echo "</form>";
                            echo "<div class='action-buttons'>";
                            echo "<form method='POST'>";
                            echo "<input type='hidden' name='product_id' value='" . $row['id'] . "'>";
                            echo "<button type='submit' name='remove_from_cart' class='remove-btn' aria-label='Remove item'>Remove</button>";
                            echo "<a href='login.php?redirect=payment.php' class='checkout-btn' aria-label='Login to checkout'>Login to Checkout</a>";
                            echo "</form>";
                            echo "</div>";
                            echo "</div>";
                        }
                        echo "</div>";
                        echo "<div class='checkout-container'>";
                        echo "<div class='cart-total'>Total: UGX " . number_format($total) . "</div>";
                        echo "<a href='login.php?redirect=payment.php' class='main-checkout-btn' aria-label='Login to checkout all items'>Login to Checkout</a>";
                        echo "</div>";
                    } else {
                        echo "<div class='empty-cart'>";
                        echo "<p>Your cart is empty. <a href='index.php'>Continue shopping</a></p>";
                        echo "</div>";
                    }
                    $stmt->close();
                } else {
                    echo "<div class='empty-cart'>";
                    echo "<p>Your cart is empty. <a href='index.php'>Continue shopping</a></p>";
                    echo "</div>";
                }
            }
            ?>
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
                        <li><a href="#" class="feedback-btn" id="footer-feedback-btn">Feedback</a></li>
                        <li><a href="#">FAQs</a></li>
                        <li><a href="Bottles.php">Student Bottles</a></li>
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
            // SCROLL TO TOP FUNCTIONALITY - ADDED
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

            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.product-card').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(el);
            });

            const searchInput = document.querySelectorAll('.search-input');
            const searchResults = document.querySelectorAll('.search-results');
            let selectedIndex = -1;

            function fetchSuggestions(query, resultsContainer) {
                if (query.length >= 2) {
                    fetch(`search.php?query=${encodeURIComponent(query)}&type=autocomplete`)
                        .then(response => response.text())
                        .then(data => {
                            resultsContainer.innerHTML = data;
                            resultsContainer.classList.add('active');
                            selectedIndex = -1;
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            resultsContainer.innerHTML = '<div class="no-results">Error fetching suggestions</div>';
                            resultsContainer.classList.add('active');
                        });
                } else {
                    resultsContainer.innerHTML = '';
                    resultsContainer.classList.remove('active');
                }
            }

            function fetchFullResults(query, resultsContainer) {
                fetch(`search.php?query=${encodeURIComponent(query)}&type=full`)
                    .then(response => response.text())
                    .then(data => {
                        resultsContainer.innerHTML = data;
                        resultsContainer.classList.add('active');
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        resultsContainer.innerHTML = '<div class="no-results">Error fetching results</div>';
                        resultsContainer.classList.add('active');
                    });
            }

            searchInput.forEach(input => {
                input.addEventListener('input', function() {
                    const resultsContainer = this.parentElement.querySelector('.search-results');
                    fetchSuggestions(this.value.trim(), resultsContainer);
                });

                input.addEventListener('keydown', function(e) {
                    const resultsContainer = this.parentElement.querySelector('.search-results');
                    const suggestions = resultsContainer.querySelectorAll('.suggestion');
                    if (suggestions.length === 0) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                        updateSelection(suggestions);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelection(suggestions);
                    } else if (e.key === 'Enter' && selectedIndex >= 0) {
                        e.preventDefault();
                        const selectedSuggestion = suggestions[selectedIndex].textContent;
                        this.value = selectedSuggestion;
                        fetchFullResults(selectedSuggestion, resultsContainer);
                    }
                });
            });

            function updateSelection(suggestions) {
                suggestions.forEach((suggestion, index) => {
                    suggestion.classList.toggle('selected', index === selectedIndex);
                });
                if (selectedIndex >= 0) {
                    const activeInput = document.querySelector('.search-input:focus');
                    if (activeInput) activeInput.value = suggestions[selectedIndex].textContent;
                }
            }

            searchResults.forEach(resultsContainer => {
                resultsContainer.addEventListener('click', function(e) {
                    const suggestion = e.target.closest('.suggestion');
                    if (suggestion) {
                        const activeInput = document.querySelector('.search-input:focus') || document.querySelector('.search-input');
                        activeInput.value = suggestion.textContent;
                        fetchFullResults(suggestion.textContent, resultsContainer);
                    }
                });
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.search-bar') && !e.target.closest('.search-results')) {
                    searchResults.forEach(resultsContainer => {
                        resultsContainer.classList.remove('active');
                    });
                }
            });

            const cartBtn = document.querySelectorAll('.cart-btn');
            cartBtn.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = 'cart.php';
                });
            });

            const menuIcon = document.querySelector('.menu-icon');
            const mobileMenu = document.querySelector('.mobile-menu');
            const closeIcon = document.querySelector('.close-icon');
            const feedbackBtn = document.getElementById('floating-feedback-btn');
            const mobileFeedbackBtn = document.getElementById('mobile-feedback-btn');
            const footerFeedbackBtn = document.getElementById('footer-feedback-btn');
            const feedbackModal = document.getElementById('feedback-modal');
            const feedbackModalClose = document.getElementById('feedback-modal-close');
            const feedbackForm = document.getElementById('feedback-form');
            const feedbackMessage = document.getElementById('feedback-message');
            const imageModal = document.getElementById('image-modal');
            const imageModalClose = document.getElementById('image-modal-close');
            const modalImage = document.getElementById('modal-image');
            const modalTitle = document.getElementById('modal-title');
            const modalCaption = document.getElementById('modal-caption');
            const modalPrice = document.getElementById('modal-price');
            const modalSubtotal = document.getElementById('modal-subtotal');
            const modalProductId = document.getElementById('modal-product-id');
            const modalQuantity = document.getElementById('modal-quantity');
            const modalCheckoutBtn = document.getElementById('modal-checkout-btn');
            const chatbotBtn = document.getElementById('floating-chatbot-btn');
            const mobileChatbotBtn = document.getElementById('mobile-chatbot-btn');
            const chatbotModal = document.getElementById('chatbot-modal');
            const chatbotModalClose = document.getElementById('chatbot-modal-close');
            const chatbotForm = document.getElementById('chatbot-form');
            const chatbotMessages = document.getElementById('chatbot-messages');
            const chatbotInput = document.getElementById('chatbot-input');

            menuIcon.addEventListener('click', function() {
                mobileMenu.classList.add('active');
            });

            closeIcon.addEventListener('click', function() {
                mobileMenu.classList.remove('active');
            });

            mobileMenu.addEventListener('click', function(e) {
                if (e.target.classList.contains('mobile-nav') || e.target.tagName === 'A') {
                    mobileMenu.classList.remove('active');
                }
            });

            feedbackBtn.addEventListener('click', function() {
                feedbackModal.style.display = 'flex';
                feedbackMessage.style.display = 'none';
            });

            mobileFeedbackBtn.addEventListener('click', function() {
                feedbackModal.style.display = 'flex';
                feedbackMessage.style.display = 'none';
            });

            footerFeedbackBtn.addEventListener('click', function(e) {
                e.preventDefault();
                feedbackModal.style.display = 'flex';
                feedbackMessage.style.display = 'none';
            });

            feedbackModalClose.addEventListener('click', function() {
                feedbackModal.style.display = 'none';
                feedbackForm.reset();
                feedbackMessage.style.display = 'none';
            });

            feedbackModal.addEventListener('click', function(e) {
                if (e.target === feedbackModal) {
                    feedbackModal.style.display = 'none';
                    feedbackForm.reset();
                    feedbackMessage.style.display = 'none';
                }
            });

            // Chatbot functionality
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

            chatbotBtn.addEventListener('click', openChatbotModal);
            mobileChatbotBtn.addEventListener('click', openChatbotModal);

            chatbotModalClose.addEventListener('click', closeChatbotModal);

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

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (feedbackModal.style.display === 'flex') {
                        feedbackModal.style.display = 'none';
                        feedbackForm.reset();
                        feedbackMessage.style.display = 'none';
                    }
                    if (imageModal.style.display === 'flex') {
                        imageModal.style.display = 'none';
                    }
                    if (chatbotModal.style.display === 'flex') {
                        closeChatbotModal();
                    }
                    if (mobileMenu.classList.contains('active')) {
                        mobileMenu.classList.remove('active');
                    }
                }
            });

            feedbackForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(feedbackForm);
                formData.append('submit_feedback', 'true');
                fetch('cart.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' . response.statusText);
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
                    feedbackMessage.textContent = 'An error occurred: ' + error.message;
                });
            });

            document.querySelectorAll('.product-card img').forEach(img => {
                img.addEventListener('click', function() {
                    const productId = this.getAttribute('data-product-id');
                    const cartId = this.getAttribute('data-cart-id') || '';
                    const title = this.getAttribute('data-title');
                    const caption = this.getAttribute('data-caption');
                    const price = this.getAttribute('data-price');
                    const subtotal = this.getAttribute('data-subtotal');
                    const quantity = this.closest('.product-card').querySelector('input[name="quantity"]').value;

                    modalImage.src = this.src;
                    modalImage.alt = title;
                    modalTitle.textContent = title;
                    modalCaption.textContent = caption;
                    modalPrice.textContent = price;
                    modalSubtotal.textContent = subtotal;
                    modalProductId.value = productId;
                    modalQuantity.value = quantity;
                    if (modalCheckoutBtn && cartId) {
                        modalCheckoutBtn.href = 'payment.php?cart_id=' + cartId;
                    }

                    imageModal.style.display = 'flex';
                });
            });

            imageModalClose.addEventListener('click', function() {
                imageModal.style.display = 'none';
            });

            imageModal.addEventListener('click', function(e) {
                if (e.target === imageModal) {
                    imageModal.style.display = 'none';
                }
            });

            document.querySelectorAll('.quantity-control input[type="number"]').forEach(input => {
                input.addEventListener('blur', function() {
                    if (/^[0-9]+$/.test(this.value) && parseInt(this.value) >= 1) {
                        const form = this.closest('form');
                        if (!form.querySelector('input[name="update_quantity"]')) {
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'update_quantity';
                            hidden.value = '1';
                            form.appendChild(hidden);
                        }
                        form.submit();
                    }
                });

                input.addEventListener('input', function() {
                    if (!/^[0-9]+$/.test(this.value) || parseInt(this.value) < 1) {
                        this.setCustomValidity('Please enter a valid positive number');
                        this.reportValidity();
                    } else {
                        this.setCustomValidity('');
                    }
                });
            });

            document.querySelectorAll('.checkout-btn, .main-checkout-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    const forms = document.querySelectorAll('.quantity-control');
                    for (let form of forms) {
                        const input = form.querySelector('input[name="quantity"]');
                        if (input.value !== input.defaultValue && /^[0-9]+$/.test(input.value) && parseInt(input.value) >= 1) {
                            if (!confirm('You have unsaved quantity changes. Update quantities before proceeding?')) {
                                e.preventDefault();
                                return;
                            }
                            if (!form.querySelector('input[name="update_quantity"]')) {
                                const hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = 'update_quantity';
                                hidden.value = '1';
                                form.appendChild(hidden);
                            }
                            form.submit();
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>