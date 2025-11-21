<?php
session_start();
include 'db_connect.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.php?redirect=payment.php&cart_id=" . urlencode($_GET['cart_id'] ?? ''));
    exit();
}

$user_id = intval($_SESSION['user_id']);
$cart_items = [];
$total = 0;
$is_single_item = isset($_GET['cart_id']) && is_numeric($_GET['cart_id']);

// Commented out Stripe initialization to avoid autoload error
// require_once 'vendor/autoload.php';
// \Stripe\Stripe::setApiKey('your_stripe_secret_key');

if ($is_single_item) {
    // Fetch specific cart item WITH PRODUCT ID
    $cart_id = (int)$_GET['cart_id'];
    $stmt = $conn->prepare("SELECT c.id AS cart_id, p.id, p.name, p.price, p.image_path, c.quantity 
                            FROM cart c 
                            JOIN products p ON c.product_id = p.id 
                            WHERE c.id = ? AND c.user_id = ?");
    $stmt->bind_param("ii", $cart_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        header("Location: cart.php");
        exit();
    }
    $cart_items[] = $result->fetch_assoc();
    $total = $cart_items[0]['price'] * $cart_items[0]['quantity'];
    $stmt->close();
} else {
    // Fetch all cart items for the user WITH PRODUCT ID
    $stmt = $conn->prepare("SELECT c.id AS cart_id, p.id, p.name, p.price, p.image_path, c.quantity 
                            FROM cart c 
                            JOIN products p ON c.product_id = p.id 
                            WHERE c.user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        header("Location: cart.php");
        exit();
    }
    while ($row = $result->fetch_assoc()) {
        $cart_items[] = $row;
        $total += $row['price'] * $row['quantity'];
    }
    $stmt->close();
}
// Coupon Logic
$discount_amount = 0;
$discount_code = '';
$coupon_message = '';
$applied_discount = 0;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['apply_coupon'])) {
    $coupon_code = strtoupper(trim($_POST['coupon_code']));
    $stmt = $conn->prepare("SELECT * FROM coupons WHERE code = ? AND is_active = 1 AND (valid_until IS NULL OR valid_until >= CURDATE())");
    $stmt->bind_param("s", $coupon_code);
    $stmt->execute();
    $coupon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($coupon) {
        $applied_discount = ($subtotal * $coupon['discount_percent']) / 100;
        $discount_amount = $applied_discount;
        $discount_code = $coupon_code;
        $coupon_message = "<span style='color:green;'>Coupon applied! Save UGX " . number_format($applied_discount) . "</span>";
    } else {
        $coupon_message = "<span style='color:red;'>Invalid or expired coupon.</span>";
    }
}


// Process feedback form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_feedback'])) {
    $name = trim($_POST['feedback_name']);
    $email = trim($_POST['feedback_email']);
    $message = trim($_POST['feedback_message']);
    $user_id_feedback = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    // Basic validation
    if (empty($name) || empty($email) || empty($message)) {
        $response = ['success' => false, 'message' => 'All fields are required.'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response = ['success' => false, 'message' => 'Invalid email format.'];
    } else {
        $stmt = $conn->prepare("INSERT INTO feedback (user_id, name, email, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id_feedback, $name, $email, $message);
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Feedback submitted successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to submit feedback. Please try again.'];
            error_log("Failed to submit feedback: " . $stmt->error);
        }
        $stmt->close();
    }

    // Return JSON response for AJAX
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
// ================================================================
//  PROCESS PAYMENT – Mobile Money & Pay on Delivery ONLY
// ================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['submit_feedback']) && !isset($_POST['apply_coupon'])) {

    $payment_method   = $_POST['payment_method'] ?? '';
    $phone            = $conn->real_escape_string($_POST['phone'] ?? '');
    $location         = $conn->real_escape_string($_POST['location'] ?? '');
    $network_provider = ($payment_method === 'Mobile Money') ? ($_POST['network_provider'] ?? '') : null;
    $username         = $conn->real_escape_string($_SESSION['username']);

    // ---------- VALIDATION ----------
    $errors = [];
    if (!preg_match("/^(07[0-9]{8}|\+256[0-9]{8})$/", $phone)) {
        $errors[] = "Invalid phone number. Use 07XXXXXXXX or +256XXXXXXXX.";
    }
    if (empty($location)) {
        $errors[] = "Delivery location is required.";
    }
    if ($payment_method === 'Mobile Money' && empty($network_provider)) {
        $errors[] = "Please select a network provider.";
    }

    if (!empty($errors)) {
        $message = implode('<br>', $errors);
    } else {
        // ---------- TRANSACTION ----------
        $conn->autocommit(false);
        $order_id   = null;
        $success    = false;
        $msg        = '';

        try {
            // 1. INSERT ONE ORDER (your exact columns)
            $order_date   = date('Y-m-d H:i:s');
            $order_status = 'Pending';
            $order_total  = $total;                     // $total already calculated above

            $stmt = $conn->prepare("INSERT INTO orders 
                (user_id, order_date, total_amount, status) 
                VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isds", $user_id, $order_date, $order_total, $order_status);
            $stmt->execute();
            $order_id = $conn->insert_id;
            $stmt->close();

            if (!$order_id) {
                throw new Exception("Failed to get order ID.");
            }

            // 2. INSERT EACH ITEM INTO order_items & pending_deliveries
            foreach ($cart_items as $item) {
                $cart_id      = $item['cart_id'];
                $product_id   = $item['id'];
                $product_name = $item['name'];
                $product_img  = $item['image_path'] ?? null;
                $quantity     = $item['quantity'];
                $price        = $item['price'];
                $item_total   = $price * $quantity;

                // ---- order_items ----
                $stmt = $conn->prepare("INSERT INTO order_items 
                    (order_id, product_id, product_name, quantity, price) 
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iisid", $order_id, $product_id, $product_name, $quantity, $price);
                $stmt->execute();
                $stmt->close();

                // ---- pending_deliveries (one row per product) ----
                $amount_for_row = ($payment_method === 'Mobile Money') ? $item_total : null;

                $stmt = $conn->prepare("INSERT INTO pending_deliveries 
                    (user_id, username, phone, payment_method, amount, cart_id, location, network_provider,
                     product_id, product_name, product_image, quantity) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssdississi",
                    $user_id, $username, $phone, $payment_method, $amount_for_row,
                    $cart_id, $location, $network_provider,
                    $product_id, $product_name, $product_img, $quantity);
                $stmt->execute();
                $stmt->close();
            }

            // 3. CLEAR CART
            $cart_ids = array_column($cart_items, 'cart_id');
            if (!empty($cart_ids)) {
                $placeholders = str_repeat('?,', count($cart_ids) - 1) . '?';
                $types        = str_repeat('i', count($cart_ids));
                $stmt = $conn->prepare("DELETE FROM cart WHERE id IN ($placeholders) AND user_id = ?");
                $params = array_merge($cart_ids, [$user_id]);
                $ref = [];
                foreach ($params as $k => $v) $ref[$k] = &$params[$k];
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types . 'i'], $ref));
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $success = true;
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Payment error: " . $e->getMessage());
            $message = "Order failed – please try again.";
        }

        $conn->autocommit(true);

        // ---------- SUCCESS ----------
        if ($success && $order_id) {
            if ($payment_method === 'Mobile Money') {
                $msg = "Payment of UGX " . number_format($order_total) .
                       " initiated via $network_provider. Order #$order_id is Pending.";
            } else {
                $msg = "Pay on Delivery confirmed. Order #$order_id will be delivered to: " .
                       htmlspecialchars($location) . ".";
            }

            $_SESSION['payment_success'] = $msg;
            $_SESSION['order_id']        = $order_id;
            header("Location: payment_success.php");
            exit();
        }
    }
}

// Set user_email to empty string (no email column in users table)
$user_email = '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Bugema CampusShop</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://js.stripe.com/v3/"></script>
    <!-- PayPal SDK included but not initialized -->
    <script src="https://www.paypal.com/sdk/js?client-id=your_paypal_client_id&currency=UGX"></script>
    <style>
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
            background: var(--light-gray);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: hidden;
            padding-bottom: 0;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><path d="M0,10 Q25,0 50,10 T100,10" fill="none" stroke="%23ffffff" stroke-opacity=".1" stroke-width="2"/></svg>') repeat-x bottom;
            z-index: 0;
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
            position: relative;
            z-index: 1;
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

        .mobile-username {
            color: var(--dark-gray);
            font-size: 1rem;
            font-weight: 500;
            padding: 0.5rem;
            border-radius: 8px;
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
            padding: 3px;
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

        .payment-container {
            background: var(--white);
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            max-width: 600px;
            margin: 2rem auto;
            text-align: center;
            animation: fadeIn 0.6s ease-out;
            z-index: 1;
        }

        .payment-container h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 1rem;
        }

        .product-details {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            text-align: left;
            flex-wrap: wrap;
        }

        .product-details img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            background: var(--light-gray);
        }

        .product-details .info {
            flex: 1;
            min-width: 200px;
        }

        .product-details h3 {
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }

        .product-details p {
            font-size: 0.9rem;
            color: var(--text-gray);
        }

        .payment-options {
            margin-top: 1.5rem;
            align-items: center;
        }

        .payment-option {
            margin-bottom: 1rem;
            align-items: center;
        }

        .payment-option-header {
            background: var(--light-gray);
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 1rem;
            font-weight: 500;
            color: var(--dark-gray);
            transition: background 0.3s ease;
        }

        .payment-option-header:hover {
            background: var(--secondary-green);
            color: var(--white);
        }

        .payment-option-header img {
            width: 70px;
            height: 40px;
            margin-right: 10px;
        }

        .payment-option-header .toggle-icon::after {
            content: '▼';
            font-size: 0.8rem;
        }

        .payment-option-header.active .toggle-icon::after {
            content: '▲';
        }

        .payment-option-content {
            display: none;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 0.5rem;
            background: var(--white);
        }

        .payment-option-content.active {
            display: block;
        }

        .payment-container label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark-gray);
            text-align: left;
            margin-bottom: 0.5rem;
        }

        .payment-container input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--text-gray);
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            transition: border-color 0.3s ease;
        }

        .payment-container input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 5px rgba(5, 150, 105, 0.3);
        }

        .payment-container select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--text-gray);
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            background: var(--white);
            cursor: pointer;
        }

        .payment-container button {
            width: 100%;
            padding: 10px;
            background: var(--accent-yellow);
            color: var(--dark-gray);
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease, transform 0.3s ease;
            margin-bottom: 0.5rem;
        }

        .payment-container button:hover {
            background: var(--secondary-green);
            color: var(--white);
            transform: translateY(-2px);
        }

        #card-element {
            padding: 10px;
            border: 1px solid var(--text-gray);
            border-radius: 8px;
            margin-bottom: 1rem;
            background: var(--white);
        }

        #paypal-button-container {
            margin-bottom: 1rem;
            text-align: center;
        }

        .paypal-placeholder {
            background: #003087;
            color: var(--white);
            padding: 10px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: 1rem;
        }

        .message {
            color: var(--success-green);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            background: rgba(16, 185, 129, 0.1);
            padding: 8px;
            border-radius: 8px;
        }

        .error {
            color: var(--error-red);
            background: rgba(220, 38, 38, 0.1);
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

            .payment-container {
                max-width: 500px;
                padding: 1.5rem;
            }

            .header-top {
                justify-content: space-between;
            }

            .menu-icon {
                display: block;
            }

            .header-actions {
                display: none;
            }

            .bottom-bar {
                display: block;
            }

            .floating-buttons {
                display: none;
            }

            .product-details {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .product-details img {
                width: 80px;
                height: 80px;
            }

            .modal-content {
                width: 95%;
                padding: 1.5rem;
            }
        }

        @media (min-width: 769px) {
            .menu-icon,
            .mobile-menu,
            .bottom-bar {
                display: none;
            }

            .header-actions {
                display: flex;
            }
        }

        @media (max-width: 480px) {
            .container {
                max-width: 100%;
            }

            .payment-container h2 {
                font-size: 1.5rem;
            }

            .product-details h3 {
                font-size: 1rem;
            }

            .product-details p {
                font-size: 0.8rem;
            }

            .product-details img {
                width: 60px;
                height: 60px;
            }

            .payment-container input,
            .payment-container button,
            .payment-container select {
                font-size: 0.8rem;
            }

            .username a {
                font-size: 0.9rem;
                padding: 8px 15px;
            }

            .payment-option-header {
                font-size: 0.9rem;
            }
            .bottom-bar-actions a, .bottom-bar-actions button {
                padding: 6px;
                font-size: 1.2rem;
                width: 36px;
                height: 36px;
            }
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
                <div class="header-actions">
                    <span class="username"><a href="profile.php">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></a></span>
                    <a href="index.php" class="header-btn"><i class="fas fa-home"></i></a>
                    <a href="cart.php" class="header-btn"><i class="fas fa-shopping-cart"></i></a>
                    <a href="logout.php" class="header-btn"><i class="fas fa-sign-out-alt"></i></a>
                </div>
            </div>
            <div class="mobile-menu">
                <button class="close-icon">✖</button>
                <span class="mobile-username"><a href="profile.php">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></a></span>
                <div class="mobile-nav">
                    <a href="index.php">Home</a>
                    <a href="cart.php">Cart</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="bottom-bar">
        <div class="bottom-bar-actions">
            <a href="profile.php" data-tooltip="Profile"><i class="fas fa-user"></i></a>
            <a href="index.php" data-tooltip="Home"><i class="fas fa-home"></i></a>
            <a href="cart.php" data-tooltip="Cart"><i class="fas fa-shopping-cart"></i></a>
            <button class="feedback-btn" id="mobile-feedback-btn" data-tooltip="Feedback"><i class="fas fa-comments"></i></button>
            <a href="https://wa.me/+256755087665" target="_blank" data-tooltip="Help"><i class="fab fa-whatsapp"></i></a>
        </div>
    </div>

    <!-- SCROLL TO TOP BUTTON - ADDED -->
    <button class="scroll-to-top" id="scrollToTop" title="Back to Top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div class="floating-buttons">
        <button class="floating-btn feedback-btn" id="floating-feedback-btn" data-tooltip="Feedback"><i class="fas fa-comments"></i></button>
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

    <div class="container">
        <div class="payment-container">
            <h2>Payment for <?php echo $is_single_item ? htmlspecialchars($cart_items[0]['name']) : 'All Cart Items'; ?></h2>
            <?php foreach ($cart_items as $item): ?>
                <div class="product-details">
                    <img src="<?php echo !empty($item['image_path']) ? htmlspecialchars($item['image_path']) : 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+A8AAQAB3gB4cAAAAABJRU5ErkJggg=='; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                    <div class="info">
                        <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                        <p>Price: UGX <?php echo number_format($item['price']); ?> x <?php echo $item['quantity']; ?> = UGX <?php echo number_format($item['price'] * $item['quantity']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (isset($message)): ?>
                <p class="message <?php echo strpos($message, 'Failed') !== false || strpos($message, 'Invalid') !== false ? 'error' : ''; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </p>
            <?php endif; ?>
            <div class="payment-options">
                <!-- Mobile Money -->
                <div class="payment-option">
                    <div class="payment-option-header">
                        <span>
                            <img src="images/mobile money.png" alt="Mobile Money">
                            Mobile Money
                        </span>
                        <span class="toggle-icon"></span>
                    </div>
                    <div class="payment-option-content">
                        <div class="payment-container">
                            <form id="mobile-money-form" method="POST">
                                <input type="hidden" name="payment_method" value="Mobile Money">
                                <label for="network_provider">Select Network</label>
                                <select id="network_provider" name="network_provider" required>
                                    <option value="">Select Provider</option>
                                    <option value="Airtel">Airtel</option>
                                    <option value="MTN">MTN</option>
                                </select>
                                <label for="phone_mm">Mobile Money Number</label>
                                <input type="text" id="phone_mm" name="phone" placeholder="e.g., 0771234567 or +256771234567" required>
                                <label for="location_mm">Delivery Location</label>
                                <input type="text" id="location_mm" name="location" placeholder="e.g., Room 12, Hostel A, Bugema University" required>
                                <label for="amount_mm">Total Amount (UGX)</label>
                                <input type="number" id="amount_mm" name="amount" value="<?php echo number_format($total, 0, '', ''); ?>" readonly>
                                <button type="submit">Pay with Mobile Money</button>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- Pay on Delivery -->
                <div class="payment-option">
                    <div class="payment-option-header">
                        <span>
                            <img src="images/pay on delivery.jpeg" alt="Pay on Delivery">
                            Pay on Delivery
                        </span>
                        <span class="toggle-icon"></span>
                    </div>
                    <div class="payment-option-content">
                        <div class="payment-container">
                            <form id="cod-form" method="POST">
                                <input type="hidden" name="payment_method" value="Pay on Delivery">
                                <label for="phone_cod">Phone Number for Delivery</label>
                                <input type="text" id="phone_cod" name="phone" placeholder="e.g., 0771234567 or +256771234567" required>
                                <label for="location_cod">Delivery Location</label>
                                <input type="text" id="location_cod" name="location" placeholder="e.g., Room 12, Hostel A, Bugema University" required>
                                <button type="submit">Pay on Delivery</button>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- Visa/Mastercard (Stripe) -->
                <div class="payment-option">
                    <div class="payment-option-header">
                        <span>
                            <img src="images/visa.png" alt="Visa/Mastercard">
                            Visa/Mastercard
                        </span>
                        <span class="toggle-icon"></span>
                    </div>
                    <div class="payment-option-content">
                        <div class="payment-container">
                            <form id="stripe-form" method="POST">
                                <input type="hidden" name="payment_method" value="Stripe">
                                <label for="phone_stripe">Phone Number for Delivery</label>
                                <input type="text" id="phone_stripe" name="phone" placeholder="e.g., 0771234567 or +256771234567" required>
                                <label for="location_stripe">Delivery Location</label>
                                <input type="text" id="location_stripe" name="location" placeholder="e.g., Room 12, Hostel A, Bugema University" required>
                                <label for="card-element">Credit or Debit Card</label>
                                <div id="card-element"></div>
                                <div id="card-errors" role="alert" class="error" style="display: none;"></div>
                                <button type="submit">Pay with Visa/Mastercard</button>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- PayPal -->
                <div class="payment-option">
                    <div class="payment-option-header">
                        <span>
                            <img src="images/paypal.png" alt="PayPal">
                            PayPal
                        </span>
                        <span class="toggle-icon"></span>
                    </div>
                    <div class="payment-option-content">
                        <div id="paypal-button-container" class="paypal-placeholder">PayPal Checkout (Coming Soon)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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

            const menuIcon = document.querySelector('.menu-icon');
            const mobileMenu = document.querySelector('.mobile-menu');
            const closeIcon = document.querySelector('.close-icon');
            const feedbackBtn = document.getElementById('floating-feedback-btn');
            const mobileFeedbackBtn = document.getElementById('mobile-feedback-btn');
            const feedbackModal = document.getElementById('feedback-modal');
            const feedbackModalClose = document.getElementById('feedback-modal-close');
            const feedbackForm = document.getElementById('feedback-form');
            const feedbackMessage = document.getElementById('feedback-message');

            // Accordion functionality for payment options
            const paymentHeaders = document.querySelectorAll('.payment-option-header');
            paymentHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const content = this.nextElementSibling;
                    const isActive = content.classList.contains('active');

                    // Close all other sections
                    document.querySelectorAll('.payment-option-content').forEach(c => {
                        c.classList.remove('active');
                        c.previousElementSibling.classList.remove('active');
                    });

                    // Toggle current section
                    if (!isActive) {
                        content.classList.add('active');
                        this.classList.add('active');
                    }
                });
            });

            // Simulate Stripe card element (for UI only)
            const cardElement = document.getElementById('card-element');
            cardElement.innerHTML = '<input type="text" placeholder="Card Number (e.g., 4242 4242 4242 4242)" disabled><input type="text" placeholder="MM/YY" disabled><input type="text" placeholder="CVC" disabled>';
            cardElement.style.display = 'flex';
            cardElement.style.gap = '10px';
            cardElement.querySelectorAll('input').forEach(input => {
                input.style.flex = '1';
                input.style.padding = '10px';
                input.style.border = '1px solid var(--text-gray)';
                input.style.borderRadius = '8px';
                input.style.background = '#f3f4f6';
            });

            // Disable Stripe and PayPal form submissions for now
            document.getElementById('stripe-form').addEventListener('submit', function(e) {
                e.preventDefault();
                alert('Visa/Mastercard payment is not yet enabled. Please use Mobile Money or Pay on Delivery.');
            });

            // Feedback form handling
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

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (feedbackModal.style.display === 'flex') {
                        feedbackModal.style.display = 'none';
                        feedbackForm.reset();
                        feedbackMessage.style.display = 'none';
                    }
                }
            });

            feedbackForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(feedbackForm);
                formData.append('submit_feedback', 'true');
                fetch('payment.php', {
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
                    feedbackMessage.textContent = 'An error occurred: ' + error.message;
                });
            });
        });
    </script>
</body>

</html>

<?php
$conn->close();
?>