<?php
session_start();
include 'db_connect.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Redirect to login if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Fetch user data
$stmt = $conn->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Debug: Log profile picture path
if (empty($user['profile_picture'])) {
    error_log("Profile picture is empty for user ID: $user_id");
} else {
    error_log("Profile picture path for user ID $user_id: " . $user['profile_picture']);
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_username = trim($_POST['username']);
    $profile_picture = $user['profile_picture'];

    // Handle profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'Uploads/';
        // Create Uploads directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $file_type = $_FILES['profile_picture']['type'];
        $file_size = $_FILES['profile_picture']['size'];
        $file_tmp = $_FILES['profile_picture']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $file_name = uniqid() . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;

        // Check if Uploads directory is writable
        if (!is_writable($upload_dir)) {
            $error = "Uploads directory is not writable.";
            error_log("Profile picture upload failed: Uploads directory not writable");
        } elseif (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
            if (move_uploaded_file($file_tmp, $file_path)) {
                // Delete old profile picture if exists and is not default
                if ($profile_picture && file_exists($profile_picture) && $profile_picture !== 'default-avatar.png') {
                    unlink($profile_picture);
                }
                $profile_picture = $file_path;
            } else {
                $error = "Failed to upload profile picture.";
                error_log("Profile picture upload failed: Unable to move file to $file_path");
            }
        } else {
            $error = "Invalid file type or size exceeds 5MB.";
            error_log("Profile picture upload failed: Invalid file type ($file_type) or size ($file_size)");
        }
    } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $error = "File upload error: " . $_FILES['profile_picture']['error'];
        error_log("Profile picture upload error code: " . $_FILES['profile_picture']['error']);
    }

    // Update username and profile picture
    if (!isset($error)) {
        $stmt = $conn->prepare("UPDATE users SET username = ?, profile_picture = ? WHERE id = ?");
        $stmt->bind_param("ssi", $new_username, $profile_picture, $user_id);
        if ($stmt->execute()) {
            $_SESSION['username'] = $new_username;
            $success = "Profile updated successfully!";
            // Refresh user data
            $stmt = $conn->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $error = "Failed to update profile: " . $stmt->error;
        }
    }
}

// Handle discount code application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_discount'])) {
    $discount_code = trim($_POST['discount_code']);
    
    // Validate discount code
    $valid_codes = [
        'WELCOMEBUGEMA' => 10, // 10% discount
        'STUDENT10' => 10,     // 10% discount
        'FIRSTORDER' => 15,    // 15% discount
        'CAMPUS2024' => 20     // 20% discount
    ];
    
    if (array_key_exists(strtoupper($discount_code), $valid_codes)) {
        $discount_percent = $valid_codes[strtoupper($discount_code)];
        $_SESSION['discount_code'] = $discount_code;
        $_SESSION['discount_percent'] = $discount_percent;
        $discount_success = "Discount code applied! You'll get $discount_percent% off your next order.";
    } else {
        $discount_error = "Invalid discount code. Please try again.";
    }
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

// Handle receipt generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_receipt'])) {
    $order_id = (int)$_POST['order_id'];
    
    // Fetch order details
    $stmt = $conn->prepare("SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ? AND o.user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($order) {
        // Fetch order items
        $stmt = $conn->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $order_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Generate receipt HTML
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Order Receipt - Bugema CampusShop</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 20px; }
                .order-info { margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .total { font-weight: bold; text-align: right; }
                .footer { margin-top: 30px; text-align: center; font-size: 0.9em; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Bugema CampusShop</h1>
                <h2>Order Receipt</h2>
            </div>
            
            <div class="order-info">
                <p><strong>Order ID:</strong> #<?= $order['id'] ?></p>
                <p><strong>Order Date:</strong> <?= date('F j, Y g:i A', strtotime($order['order_date'])) ?></p>
                <p><strong>Customer:</strong> <?= htmlspecialchars($order['username']) ?></p>
                <p><strong>Email:</strong> <?= htmlspecialchars($order['email']) ?></p>
                <p><strong>Status:</strong> <?= ucfirst($order['status']) ?></p>
                <p><strong>Payment Method:</strong> <?= htmlspecialchars($order['payment_method'] ?? 'N/A') ?></p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Price (UGX)</th>
                        <th>Subtotal (UGX)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($order_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= $item['quantity'] ?></td>
                        <td><?= number_format($item['price'], 0) ?></td>
                        <td><?= number_format($item['price'] * $item['quantity'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="total">Total:</td>
                        <td><?= number_format($order['total_amount'], 0) ?> UGX</td>
                    </tr>
                </tfoot>
            </table>
            
            <div class="footer">
                <p>Thank you for shopping with Bugema CampusShop!</p>
                <p>For any inquiries, contact us at +256 7550 87665 or campusshop@bugemauniv.ac.ug</p>
            </div>
        </body>
        </html>
        <?php
        $receipt_html = ob_get_clean();
        
        // Store receipt in session for printing
        $_SESSION['receipt_html'] = $receipt_html;
        $_SESSION['receipt_order_id'] = $order_id;
        
        $receipt_success = "Receipt generated successfully! You can now print it.";
    } else {
        $receipt_error = "Order not found.";
    }
}

// Fetch notifications
$notifications = [];
$stmt = $conn->prepare("SELECT message, created_at FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC LIMIT 5");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch order history with improved error handling - FIXED QUERY
$orders = [];
$order_items = [];

try {
    // First check if orders table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'orders'");
    if ($table_check->num_rows > 0) {
        // FIXED: Added proper error handling and debugging
        $stmt = $conn->prepare("SELECT o.id, o.order_date, o.total_amount, o.status, o.payment_method, o.tracking_number
                               FROM orders o 
                               WHERE o.user_id = ? 
                               ORDER BY o.order_date DESC 
                               LIMIT 10");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $orders_result = $stmt->get_result();
                $orders = $orders_result->fetch_all(MYSQLI_ASSOC);
                error_log("Fetched " . count($orders) . " orders for user $user_id");
                
                // Debug: Log order statuses
                foreach ($orders as $order) {
                    error_log("Order ID: " . $order['id'] . ", Status: " . $order['status']);
                }
            } else {
                error_log("Error executing orders query: " . $stmt->error);
            }
            $stmt->close();

            // Fetch order items for each order
            if (!empty($orders)) {
                foreach ($orders as $order) {
                    $order_id = $order['id'];
                    $stmt_items = $conn->prepare("SELECT product_name, quantity, price FROM order_items WHERE order_id = ?");
                    if ($stmt_items) {
                        $stmt_items->bind_param("i", $order_id);
                        $stmt_items->execute();
                        $items_result = $stmt_items->get_result();
                        $order_items[$order_id] = $items_result->fetch_all(MYSQLI_ASSOC);
                        $stmt_items->close();
                    }
                }
            }
        } else {
            error_log("Failed to prepare orders query: " . $conn->error);
        }
    } else {
        error_log("Orders table does not exist");
    }
} catch (Exception $e) {
    error_log("Error fetching orders: " . $e->getMessage());
}

// Get favorites count
$favorites_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM favorites WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $favorites_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// Get cart count
$cart_count = 0;
$stmt = $conn->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cart_result = $stmt->get_result()->fetch_assoc();
    $cart_count = $cart_result['count'] ?? 0;
    $stmt->close();
}

// Get active discount if any
$active_discount_code = $_SESSION['discount_code'] ?? null;
$active_discount_percent = $_SESSION['discount_percent'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - Bugema CampusShop</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Your existing CSS remains the same, with FIXES for header and responsive design */
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
            --border-color: #d1d5db;
        }

        body {
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
            color: var(--dark-gray);
            background: var(--white);
            padding-bottom: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        /* FIXED: Ensure header stays static at top */
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

        .mobile-nav a:hover,
        .mobile-nav a.active {
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

        .cart-btn, .favorites-btn {
            position: relative;
            font-size: 20px;
        }

        .cart-count,
        .favorites-count {
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

        nav {
            margin-top: 0.5rem;
            background: var(--secondary-green);
            border-radius: 8px;
            padding: 0.5rem;
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

        .nav-links a:hover,
        .nav-links a.active {
            background: var(--primary-green);
        }

        .profile-container {
            display: flex;
            min-height: calc(100vh - 70px);
            gap: 20px;
            margin-top: 20px;
        }

        .profile-left {
            width: 30%;
            background-color: var(--white);
            padding: 30px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            max-height: calc(100vh - 150px);
            margin-left: 30px;
            margin-bottom: 30px;
            overflow-y: auto;
            position: fixed;
            margin-top: 30px;
        }

        .profile-left img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 20px;
            border: 4px solid var(--light-gray);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .profile-left h2 {
            font-size: 1.8rem;
            font-weight: 600;
            text-align: center;
            color: var(--primary-green);
            margin-bottom: 25px;
        }

        .profile-left form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .profile-left label {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--dark-gray);
            margin-bottom: 5px;
        }

        .profile-left input[type="text"],
        .profile-left input[type="file"] {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            color: var(--dark-gray);
            transition: border-color 0.3s ease;
        }

        .profile-left input[type="text"]:focus,
        .profile-left input[type="file"]:focus {
            border-color: var(--secondary-green);
            outline: none;
            box-shadow: 0 0 5px rgba(69, 145, 231, 0.3);
        }

        .profile-left button {
            padding: 12px;
            background-color: var(--primary-green);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }

        .profile-left button:hover {
            background-color: var(--secondary-green);
            transform: translateY(-2px);
        }

        .profile-right {
            flex: 1;
            margin-left: 32%;
            padding: 30px;
        }

        .section {
            background: var(--white);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }

        .section:hover {
            transform: translateY(-2px);
        }

        .section h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--secondary-green);
        }

        .notification {
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .notification:last-child {
            border-bottom: none;
        }

        .notification p {
            font-size: 1rem;
            color: var(--dark-gray);
            margin-bottom: 8px;
        }

        .notification small {
            font-size: 0.85rem;
            color: var(--text-gray);
            font-style: italic;
        }

        /* FIXED: Responsive table for order history */
        .table-container {
            overflow-x: auto;
            margin-top: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
            min-width: 600px; /* Ensure table doesn't get too small */
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.95rem;
        }

        th {
            background-color: var(--light-gray);
            color: var(--primary-green);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            color: var(--dark-gray);
        }

        tr:hover {
            background-color: var(--light-gray);
        }

        /* Mobile table styles */
        @media (max-width: 768px) {
            table {
                font-size: 0.8rem;
            }
            th, td {
                padding: 8px 5px;
            }
            .order-actions {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
        }

        .payment-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .payment-option {
            text-align: center;
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .payment-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .payment-option img {
            width: 60px;
            height: auto;
            margin-bottom: 10px;
        }

        .payment-option p {
            font-size: 0.95rem;
            color: var(--dark-gray);
            font-weight: 500;
        }

        /* HELP CENTER - UPDATED WITH RETURN POLICY */
        .help-center {
            text-align: left;
        }

        .help-center h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--secondary-green);
        }

        .return-policy {
            background: var(--light-gray);
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid var(--primary-green);
        }

        .return-policy h5 {
            color: var(--primary-green);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .return-policy ul {
            list-style: disc;
            padding-left: 20px;
            margin: 10px 0;
        }

        .return-policy li {
            font-size: 0.9rem;
            color: var(--text-gray);
            margin-bottom: 5px;
            line-height: 1.5;
        }

        .help-center-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .help-center p {
            font-size: 0.95rem;
            color: var(--text-gray);
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .chatbot-btn {
            background: var(--light-gray);
            color: var(--primary-green);
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .chatbot-btn:hover {
            background: var(--secondary-green);
            color: var(--white);
            transform: translateY(-2px);
        }

        .account-settings {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
        }

        .account-settings a {
            color: var(--secondary-green);
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            padding: 10px 15px;
            border: 1px solid var(--secondary-green);
            width: 20%;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .account-settings a:hover {
            background-color: var(--secondary-green);
            color: var(--white);
        }

        .message {
            font-size: 0.95rem;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .message.success {
            background: var(--success-green);
            color: var(--white);
        }

        .message.error {
            background: var(--error-red);
            color: var(--white);
        }

        /* SCROLL TO TOP BUTTON - NEW */
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

        /* BOTTOM BAR */
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

        .bottom-bar-actions a,
        .bottom-bar-actions button {
            color: var(--dark-gray);
            padding: 8px;
            border-radius: 50%;
            text-decoration: none;
            font-weight: 500;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: background 0.3s ease, color 0.3s ease;
            position: relative;
            border: none;
        }

        .bottom-bar-actions a:hover,
        .bottom-bar-actions button:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        /* MODALS */
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
        }

        .modal-close:hover {
            color: var(--error-red);
        }

        .feedback-form,
        .chatbot-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .feedback-form input,
        .feedback-form textarea,
        .chatbot-form input {
            padding: 10px;
            border: 1px solid var(--text-gray);
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .feedback-form button,
        .chatbot-form button {
            background: var(--accent-yellow);
            color: var(--dark-gray);
            padding: 10px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        /* FOOTER */
        footer {
            background: var(--dark-gray);
            color: var(--white);
            padding: 2rem 0;
            margin-top: 50px;
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

        .footer-section ul li a {
            color: var(--text-gray);
            text-decoration: none;
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

        /* RESPONSIVE - IMPROVED */
        @media (max-width: 900px) {
            .profile-container {
                flex-direction: column;
            }
            .profile-left {
                width: 100%;
                position: relative;
                top: 0;
                max-height: none;
                margin-bottom: 20px;
                margin-left: 0;
            }
            .profile-right {
                margin-left: 0;
                padding: 20px;
            }
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
                max-width: 95%;
                padding: 0 10px;
            }
            .menu-icon {
                display: block;
            }
            .search-bar,
            .header-actions,
            nav {
                display: none;
            }
            .bottom-bar {
                display: block;
            }
            .help-center-buttons {
                grid-template-columns: 1fr;
            }
            .return-policy {
                padding: 15px;
            }
            .return-policy li {
                font-size: 0.85rem;
            }
            .section {
                padding: 15px;
                margin-bottom: 20px;
            }
            .profile-right {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .profile-left {
                padding: 15px;
            }
            .account-settings a {
                width: 100%;
            }
            .bottom-bar-actions a,
            .bottom-bar-actions button {
                width: 36px;
                height: 36px;
                font-size: 1.2rem;
            }
            .section h3 {
                font-size: 1.3rem;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* NEW STYLES FOR ORDER TRACKING, RECEIPTS AND DISCOUNTS */
        .order-details {
            margin-top: 10px;
            padding: 10px;
            background: var(--light-gray);
            border-radius: 8px;
            display: none;
        }
        
        .order-details.active {
            display: block;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .toggle-order-details {
            background: var(--secondary-green);
            color: var(--white);
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .toggle-order-details:hover {
            background: var(--primary-green);
        }
        
        .status-pending { color: #f39c12; }
        .status-completed { color: var(--success-green); }
        .status-cancelled { color: var(--error-red); }
        .status-processing { color: #3498db; }
        .status-ready { color: #9b59b6; }
        .status-delivered { color: var(--success-green); }
        
        .no-orders {
            text-align: center;
            padding: 20px;
            color: var(--text-gray);
        }
        
        .no-orders a {
            color: var(--secondary-green);
            text-decoration: none;
            font-weight: 500;
        }
        
        .no-orders a:hover {
            text-decoration: underline;
        }
        
        /* Order tracking styles */
        .tracking-container {
            margin-top: 15px;
            padding: 15px;
            background: var(--light-gray);
            border-radius: 8px;
        }
        
        .tracking-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 20px 0;
        }
        
        .tracking-steps::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--border-color);
            z-index: 1;
        }
        
        .tracking-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        
        .step-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
            color: var(--white);
        }
        
        .step-icon.active {
            background: var(--primary-green);
        }
        
        .step-icon.completed {
            background: var(--success-green);
        }
        
        .step-label {
            font-size: 0.7rem;
            text-align: center;
            color: var(--text-gray);
            word-break: break-word;
        }
        
        .step-label.active {
            color: var(--primary-green);
            font-weight: 600;
        }
        
        .tracking-number {
            font-family: monospace;
            background: var(--dark-gray);
            color: var(--white);
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 5px;
            font-size: 0.8rem;
        }
        
        /* Discount section styles */
        .discount-section {
            background: var(--white);
            border: 2px solid var(--accent-yellow);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }

        .discount-section:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(250, 204, 21, 0.2);
        }

        .discount-section h3 {
            color: var(--primary-green);
            margin-bottom: 10px;
        }

        .discount-section p {
            color: var(--text-gray);
            font-size: 0.95rem;
        }
        
        .discount-form {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .discount-form input {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            min-width: 200px;
            flex: 1;
        }
        
        .discount-form button {
            background: var(--primary-green);
            color: var(--white);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            white-space: nowrap;
        }
        
        .discount-form button:hover {
            background: var(--secondary-green);
        }
        
        .active-discount {
            background: var(--success-green);
            color: var(--white);
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 15px;
            display: inline-block;
        }
        
        /* Receipt styles */
        .receipt-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .receipt-btn {
            background: var(--secondary-green);
            color: var(--white);
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        
        .receipt-btn:hover {
            background: var(--primary-green);
        }
        
        /* Print receipt styles */
        @media print {
            body * {
                visibility: hidden;
            }
            #receipt-print, #receipt-print * {
                visibility: visible;
            }
            #receipt-print {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
        }

        /* Order actions for mobile */
        .order-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-top">
                <div class="logo">
                    <div class="logo-icon">
                        <img style="height: 50px; width: 50px; border-radius:25px;" src="images/download.png" alt="Bugema CampusShop Logo">
                    </div>
                    <span>Bugema CampusShop</span>
                </div>
                <button class="menu-icon">☰</button>
                <div class="search-bar">
                    <input type="text" class="search-input" placeholder="Search for Bags, Branded Jumpers, Pens...">
                    <button class="search-btn"><i class="fas fa-search"></i></button>
                    <div class="search-results"></div>
                </div>
                <div class="header-actions">
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
                <span class="mobile-username"><a href="profile.php">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></a></span>
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
                    <a href="profile.php" class="active">Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="bottom-bar">
        <div class="bottom-bar-actions">
            <a href="profile.php" data-tooltip="Profile"><i class="fas fa-user"></i></a>
            <a href="favorites.php" data-tooltip="Favorites"><i class="fas fa-heart"></i> <span class="favorites-count"><?php echo $favorites_count; ?></span></a>
            <a href="cart.php" data-tooltip="Cart"><i class="fas fa-shopping-cart"></i> <span class="cart-count"><?php echo $cart_count; ?></span></a>
            <button class="feedback-btn" id="mobile-feedback-btn" data-tooltip="Feedback"><i class="fas fa-comments"></i></button>
            <a href="https://wa.me/+256755087665" target="_blank" data-tooltip="Help"><i class="fab fa-whatsapp"></i></a>
        </div>
    </div>

    <!-- FEEDBACK MODAL -->
    <div class="modal" id="feedback-modal">
        <div class="modal-content">
            <button class="modal-close" id="feedback-modal-close">&times;</button>
            <h2>Leave Your Feedback</h2>
            <div class="feedback-message" id="feedback-message" style="display: none;"></div>
            <form id="feedback-form" class="feedback-form">
                <label for="feedback_name">Name</label>
                <input type="text" id="feedback_name" name="feedback_name" value="<?php echo htmlspecialchars($username); ?>" required>
                <label for="feedback_email">Email</label>
                <input type="email" id="feedback_email" name="feedback_email" placeholder="Enter your email" required>
                <label for="feedback_message">Message</label>
                <textarea id="feedback_message" name="feedback_message" required></textarea>
                <button type="submit" name="submit_feedback">Submit Feedback</button>
            </form>
        </div>
    </div>

    <!-- CHATBOT MODAL -->
    <div class="modal" id="chatbot-modal">
        <div class="modal-content">
            <button class="modal-close" id="chatbot-modal-close">&times;</button>
            <h2>Chat with CampusShop Support</h2>
            <div class="chatbot-messages" id="chatbot-messages"></div>
            <form id="chatbot-form" class="chatbot-form">
                <input type="text" id="chatbot-input" name="chatbot_input" placeholder="Type your message..." required>
                <button type="submit">Send</button>
            </form>
        </div>
    </div>

    <!-- RECEIPT PRINT MODAL -->
    <div class="modal" id="receipt-modal">
        <div class="modal-content" style="max-width: 800px;">
            <button class="modal-close" id="receipt-modal-close">&times;</button>
            <h2>Order Receipt</h2>
            <div id="receipt-content">
                <!-- Receipt content will be loaded here -->
            </div>
            <div class="receipt-actions" style="margin-top: 20px;">
                <button id="print-receipt" class="receipt-btn"><i class="fas fa-print"></i> Print Receipt</button>
                <button id="email-receipt" class="receipt-btn"><i class="fas fa-envelope"></i> Email Receipt</button>
            </div>
        </div>
    </div>

    <section class="profile-container">
        <!-- LEFT SIDE - PROFILE -->
        <div class="profile-left">
            <center>
                <img src="<?= htmlspecialchars($user['profile_picture'] ?? 'default-avatar.png'); ?>" alt="Profile Picture" onerror="this.src='default-avatar.png'">
                <h2><?= htmlspecialchars($user['username']); ?></h2>
            </center>

            <form method="POST" enctype="multipart/form-data">
                <label for="username">Edit Username:</label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($user['username']); ?>">

                <label for="profile_picture">Change Profile Picture:</label>
                <input type="file" name="profile_picture" id="profile_picture" accept="image/*">

                <button type="submit" name="update_profile">Update Profile</button>
            </form>
            
            <?php if (isset($success)): ?>
                <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
        </div>

        <!-- RIGHT SIDE -->
        <div class="profile-right">
            <!-- NOTIFICATIONS -->
            <div class="section">
                <h3><i class="fas fa-bell"></i> Recent Notifications</h3>
                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $note): ?>
                        <div class="notification">
                            <p><?= htmlspecialchars($note['message']); ?></p>
                            <small><?= htmlspecialchars($note['created_at']); ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No notifications found.</p>
                <?php endif; ?>
            </div>

            <!-- ORDER HISTORY & TRACKING - IMPROVED -->
            <div class="section">
                <h3><i class="fas fa-history"></i> Order History & Tracking</h3>
                <?php if (!empty($orders)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Date</th>
                                    <th>Total (UGX)</th>
                                    <th>Status</th>
                                    <th>Tracking</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>#<?= htmlspecialchars($order['id']); ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($order['order_date']))); ?></td>
                                        <td><?= htmlspecialchars(number_format($order['total_amount'], 0)); ?></td>
                                        <td>
                                            <span class="status-<?= htmlspecialchars(strtolower($order['status'])); ?>">
                                                <?= htmlspecialchars(ucfirst($order['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['tracking_number'])): ?>
                                                <span class="tracking-number"><?= htmlspecialchars($order['tracking_number']); ?></span>
                                            <?php else: ?>
                                                <span>N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="order-actions">
                                                <button class="toggle-order-details" data-order-id="<?= $order['id']; ?>">
                                                    View Items
                                                </button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="order_id" value="<?= $order['id']; ?>">
                                                    <button type="submit" name="generate_receipt" class="receipt-btn" style="margin-top: 0;">
                                                        <i class="fas fa-receipt"></i> Receipt
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="6">
                                            <div class="order-details" id="order-details-<?= $order['id']; ?>">
                                                <?php if (isset($order_items[$order['id']]) && !empty($order_items[$order['id']])): ?>
                                                    <h4>Order Items:</h4>
                                                    <?php foreach ($order_items[$order['id']] as $item): ?>
                                                        <div class="order-item">
                                                            <span><?= htmlspecialchars($item['product_name']); ?></span>
                                                            <span>Qty: <?= htmlspecialchars($item['quantity']); ?></span>
                                                            <span>UGX <?= htmlspecialchars(number_format($item['price'], 0)); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    
                                                    <!-- Order Tracking Visualization -->
                                                    <div class="tracking-container">
                                                        <h4>Order Tracking:</h4>
                                                        <div class="tracking-steps">
                                                            <?php
                                                            // Define all possible statuses in order
                                                            $all_statuses = ['pending', 'processing', 'ready', 'delivered', 'cancelled'];
                                                            $status_icons = [
                                                                'pending'     => 'fas fa-clock',
                                                                'processing'  => 'fas fa-cog',
                                                                'ready'       => 'fas fa-box',
                                                                'delivered'   => 'fas fa-check',
                                                                'cancelled'   => 'fas fa-times'
                                                            ];
                                                            $status_labels = [
                                                                'pending'     => 'Pending',
                                                                'processing'  => 'Processing',
                                                                'ready'       => 'Ready',
                                                                'delivered'   => 'Delivered',
                                                                'cancelled'   => 'Cancelled'
                                                            ];

                                                            $current_status = strtolower($order['status']);
                                                            $current_index = array_search($current_status, $all_statuses);
                                                            if ($current_index === false) $current_index = -1;

                                                            foreach ($all_statuses as $idx => $status):
                                                                $is_completed = $idx < $current_index;
                                                                $is_active    = $idx == $current_index;
                                                                $icon = $status_icons[$status] ?? 'fas fa-question';
                                                                $label = $status_labels[$status] ?? ucfirst($status);
                                                            ?>
                                                                <div class="tracking-step">
                                                                    <div class="step-icon <?= $is_completed ? 'completed' : ($is_active ? 'active' : '') ?>">
                                                                        <i class="<?= $icon ?>"></i>
                                                                    </div>
                                                                    <div class="step-label <?= $is_active ? 'active' : '' ?>">
                                                                        <?= $label ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>

                                                        <!-- Tracking Number -->
                                                        <?php if (!empty($order['tracking_number'])): ?>
                                                            <p><strong>Tracking #:</strong> <span class="tracking-number"><?= htmlspecialchars($order['tracking_number']) ?></span></p>
                                                        <?php endif; ?>

                                                        <!-- Status Message -->
                                                        <?php
                                                        $messages = [
                                                            'pending'     => 'Your order has been received and is awaiting confirmation.',
                                                            'processing'  => 'We are preparing your items. Ready in 1–2 days.',
                                                            'ready'       => 'Your order is ready! Pick up at CampusShop or expect delivery.',
                                                            'delivered'   => 'Delivered successfully. Enjoy your items!',
                                                            'cancelled'   => 'This order was cancelled.'
                                                        ];
                                                        ?>
                                                        <p><?= $messages[$current_status] ?? 'Status update coming soon.' ?></p>
                                                    </div>
                                                <?php else: ?>
                                                    <p>No items found for this order.</p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (isset($receipt_success)): ?>
                        <div class="message success"><?php echo htmlspecialchars($receipt_success); ?></div>
                        <div class="receipt-actions">
                            <button id="view-receipt" class="receipt-btn"><i class="fas fa-eye"></i> View Receipt</button>
                            <button id="print-receipt-direct" class="receipt-btn"><i class="fas fa-print"></i> Print Receipt</button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($receipt_error)): ?>
                        <div class="message error"><?php echo htmlspecialchars($receipt_error); ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-orders">
                        <p>You haven't placed any orders yet.</p>
                        <p><a href="index.php">Start shopping now!</a></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PAYMENT OPTIONS -->
            <div class="section">
                <h3><i class="fas fa-credit-card"></i> Payment Options</h3>
                <div class="payment-options">
                    <div class="payment-option">
                        <img src="images/mobile money.png" alt="Mobile Money" onerror="this.style.display='none'">
                        <p>Mobile Money</p>
                    </div>
                    <div class="payment-option">
                        <img src="images/paypal.png" alt="PayPal" onerror="this.style.display='none'">
                        <p>PayPal</p>
                    </div>
                    <div class="payment-option">
                        <img src="images/visa.png" alt="Visa" onerror="this.style.display='none'">
                        <p>Visa</p>
                    </div>
                    <div class="payment-option">
                        <img src="images/pay on delivery.jpeg" alt="Cash on Delivery" onerror="this.style.display='none'">
                        <p>Cash on Delivery</p>
                    </div>
                </div>
            </div>

            <!-- HELP CENTER -->
            <div class="section">
                <h3><i class="fas fa-question-circle"></i> Help Center</h3>
                <div class="help-center">
                    <p>Need assistance? We're here to help you with your shopping experience at Bugema CampusShop.</p>
                    
                    <!-- RETURN POLICY -->
                    <div class="return-policy">
                        <h5><i class="fas fa-shipping-fast"></i> Return Policy</h5>
                        <ul>
                            <li><strong>30-Day Return Window:</strong> Returns accepted within 30 days from delivery date</li>
                            <li><strong>Full Refund:</strong> Complete refund for unused items in original packaging</li>
                            <li><strong>Defective Items:</strong> Free return shipping for damaged or defective products</li>
                            <li><strong>Size Issues:</strong> Exchange available for wrong size clothing (T-Shirts, Jumpers)</li>
                            <li><strong>Non-Returnable:</strong> Personalized items, opened electronics, or hygiene products</li>
                            <li><strong>Process:</strong> Contact us via WhatsApp or chatbot, we'll arrange pickup within 48 hours</li>
                            <li><strong>Refund Time:</strong> Processed within 3-5 business days to original payment method</li>
                        </ul>
                        <p style="font-size: 0.85rem; color: var(--success-green); margin-top: 10px;">
                            <strong>Questions?</strong> Message us anytime!
                        </p>
                    </div>

                    <div class="help-center-buttons">
                        <button class="chatbot-btn" id="chatbot-btn">
                            <i class="fas fa-comments"></i> Chat with Us
                        </button>
                        <a href="https://wa.me/+256755087665" target="_blank" class="chatbot-btn">
                            <i class="fab fa-whatsapp"></i> WhatsApp Support
                        </a>
                        <button class="chatbot-btn" onclick="window.open('mailto:campusshop@bugemauniv.ac.ug')" style="background: var(--accent-yellow);">
                            <i class="fas fa-envelope"></i> Email Support
                        </button>
                    </div>
                </div>
            </div>

            <!-- ACCOUNT SETTINGS -->
            <div class="section">
                <h3><i class="fas fa-cog"></i> Account Settings</h3>
                <div class="account-settings">
                    <a href="change_password.php">
                        <i class="fas fa-lock"></i> Change Password
                    </a>
                    <a href="delete_account.php">
                        <i class="fas fa-user-times"></i> Delete Account
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- SCROLL TO TOP BUTTON -->
    <button class="scroll-to-top" id="scrollToTop" title="Back to Top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Hidden receipt for printing -->
    <div id="receipt-print" style="display: none;">
        <?php if (isset($_SESSION['receipt_html'])): ?>
            <?php echo $_SESSION['receipt_html']; ?>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ORDER DETAILS TOGGLE
        document.querySelectorAll('.toggle-order-details').forEach(button => {
            button.addEventListener('click', function() {
                const orderId = this.getAttribute('data-order-id');
                const detailsDiv = document.getElementById('order-details-' + orderId);
                detailsDiv.classList.toggle('active');
                this.textContent = detailsDiv.classList.contains('active') ? 'Hide Items' : 'View Items';
            });
        });

        // SCROLL TO TOP
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

        // FEEDBACK MODAL
        const feedbackBtn = document.getElementById('floating-feedback-btn') || document.getElementById('mobile-feedback-btn');
        const feedbackModal = document.getElementById('feedback-modal');
        const feedbackModalClose = document.getElementById('feedback-modal-close');
        const feedbackForm = document.getElementById('feedback-form');
        const feedbackMessage = document.getElementById('feedback-message');

        if (feedbackBtn) {
            feedbackBtn.addEventListener('click', () => {
                feedbackModal.style.display = 'flex';
                feedbackMessage.style.display = 'none';
            });
        }

        feedbackModalClose.addEventListener('click', () => {
            feedbackModal.style.display = 'none';
            feedbackForm.reset();
            feedbackMessage.style.display = 'none';
        });

        feedbackModal.addEventListener('click', (e) => {
            if (e.target === feedbackModal) {
                feedbackModal.style.display = 'none';
                feedbackForm.reset();
                feedbackMessage.style.display = 'none';
            }
        });

        feedbackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(feedbackForm);
            formData.append('submit_feedback', 'true');
            
            fetch('profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
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
                feedbackMessage.style.display = 'block';
                feedbackMessage.className = 'feedback-message error';
                feedbackMessage.textContent = 'An error occurred: ' + error.message;
            });
        });

        // RECEIPT FUNCTIONALITY
        const viewReceiptBtn = document.getElementById('view-receipt');
        const printReceiptDirectBtn = document.getElementById('print-receipt-direct');
        const receiptModal = document.getElementById('receipt-modal');
        const receiptModalClose = document.getElementById('receipt-modal-close');
        const receiptContent = document.getElementById('receipt-content');
        const printReceiptBtn = document.getElementById('print-receipt');
        const emailReceiptBtn = document.getElementById('email-receipt');

        if (viewReceiptBtn) {
            viewReceiptBtn.addEventListener('click', () => {
                receiptContent.innerHTML = document.getElementById('receipt-print').innerHTML;
                receiptModal.style.display = 'flex';
            });
        }

        if (printReceiptDirectBtn) {
            printReceiptDirectBtn.addEventListener('click', () => {
                const printContent = document.getElementById('receipt-print').innerHTML;
                const originalContent = document.body.innerHTML;
                
                document.body.innerHTML = printContent;
                window.print();
                document.body.innerHTML = originalContent;
                location.reload(); // Reload to restore functionality
            });
        }

        if (receiptModalClose) {
            receiptModalClose.addEventListener('click', () => {
                receiptModal.style.display = 'none';
            });
        }

        if (printReceiptBtn) {
            printReceiptBtn.addEventListener('click', () => {
                const printContent = document.getElementById('receipt-print').innerHTML;
                const originalContent = document.body.innerHTML;
                
                document.body.innerHTML = printContent;
                window.print();
                document.body.innerHTML = originalContent;
                location.reload(); // Reload to restore functionality
            });
        }

        if (emailReceiptBtn) {
            emailReceiptBtn.addEventListener('click', () => {
                const orderId = <?= $_SESSION['receipt_order_id'] ?? 'null' ?>;
                if (orderId) {
                    alert('Receipt for order #' + orderId + ' would be emailed to your registered email address.');
                    // In a real implementation, you would make an AJAX call to send the email
                }
            });
        }

        receiptModal.addEventListener('click', (e) => {
            if (e.target === receiptModal) {
                receiptModal.style.display = 'none';
            }
        });

        // CHATBOT
        const chatbotBtn = document.getElementById('chatbot-btn');
        const chatbotModal = document.getElementById('chatbot-modal');
        const chatbotModalClose = document.getElementById('chatbot-modal-close');
        const chatbotForm = document.getElementById('chatbot-form');
        const chatbotMessages = document.getElementById('chatbot-messages');
        const chatbotInput = document.getElementById('chatbot-input');

        const responses = {
            'hello': 'Hi! How can I assist you today?',
            'delivery': 'We offer fast campus delivery within 24 hours to your dorm or a campus pickup point.',
            'discount': 'Bugema University students with valid ID enjoy exclusive discounts. Verify at checkout!',
            'products': 'We offer Bags, branded jumpers, pens, clocks, notebooks, T-shirts, and bottles.',
            'contact': 'Reach us at +256 7550 87665 (WhatsApp) or campusshop@bugemauniv.ac.ug',
            'return': 'Our 30-day return policy covers unused items. See Help Center for details!',
            'tracking': 'You can track your orders in the Order History section of your profile.',
            'receipt': 'You can view and print receipts for your orders in the Order History section.',
            'help': 'Ask about delivery, discounts, products, returns, tracking, receipts, or contact info.',
            'default': 'Sorry, I didn\'t understand. Try: delivery, discounts, products, tracking, or returns!'
        };

        function addMessage(content, sender) {
            const messageDiv = document.createElement('div');
            messageDiv.classList.add('chatbot-message', sender);
            messageDiv.innerHTML = `<p>${content}</p>`;
            chatbotMessages.appendChild(messageDiv);
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }

        if (chatbotBtn) chatbotBtn.addEventListener('click', () => chatbotModal.style.display = 'flex');
        if (chatbotModalClose) chatbotModalClose.addEventListener('click', () => chatbotModal.style.display = 'none');
        
        chatbotModal.addEventListener('click', (e) => {
            if (e.target === chatbotModal) chatbotModal.style.display = 'none';
        });

        chatbotForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const message = chatbotInput.value.trim();
            if (!message) return;
            
            addMessage(message, 'user');
            const lowerMessage = message.toLowerCase();
            let response = responses['default'];
            
            for (const key in responses) {
                if (lowerMessage.includes(key)) {
                    response = responses[key];
                    break;
                }
            }
            
            setTimeout(() => addMessage(response, 'bot'), 500);
            chatbotInput.value = '';
        });

        // MOBILE MENU
        const menuIcon = document.querySelector('.menu-icon');
        const mobileMenu = document.querySelector('.mobile-menu');
        const closeIcon = document.querySelector('.close-icon');

        if (menuIcon) menuIcon.addEventListener('click', () => mobileMenu.classList.add('active'));
        if (closeIcon) closeIcon.addEventListener('click', () => mobileMenu.classList.remove('active'));
        if (mobileMenu) mobileMenu.addEventListener('click', (e) => {
            if (e.target.tagName === 'A') mobileMenu.classList.remove('active');
        });

        // ESCAPE KEY
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                feedbackModal.style.display = 'none';
                chatbotModal.style.display = 'none';
                receiptModal.style.display = 'none';
                mobileMenu.classList.remove('active');
            }
        });
    });
    </script>
</body>
</html>

<?php
$conn->close();
?>