<?php
include 'db_connect.php';
session_start();

$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    echo "No order found.";
    exit;
}

// Fetch order details
$result = $conn->query("SELECT * FROM orders WHERE id='$order_id'");
if ($result->num_rows == 0) {
    echo "Order not found.";
    exit;
}

$order = $result->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Receipt</title>
</head>
<body>
    <h2>Receipt for Order <?php echo $order['order_number']; ?></h2>
    <p><strong>Order Date:</strong> <?php echo $order['order_date']; ?></p>
    <p><strong>Total Amount:</strong> UGX <?php echo $order['total_amount']; ?></p>
    <p><strong>Payment Method:</strong> <?php echo $order['payment_method']; ?></p>
    <p><strong>Payment Status:</strong> <?php echo $order['payment_status']; ?></p>
    <p><strong>Shipping Address:</strong> <?php echo $order['shipping_address'] ?: "Not provided"; ?></p>
    <p><strong>Billing Address:</strong> <?php echo $order['billing_address'] ?: "Not provided"; ?></p>
</body>
</html>
