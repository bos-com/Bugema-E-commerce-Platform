<?php
include 'db_connect.php';
session_start();

if (!isset($_SESSION['order_id']) || !isset($_SESSION['user_id'])) {
    echo "No order found.";
    exit;
}

$order_id = $_SESSION['order_id'];
$user_id  = $_SESSION['user_id'];

// Fetch order
$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo "Order not found.";
    exit;
}

echo "<h2>Order Receipt</h2>";
echo "<p>Order ID: " . htmlspecialchars($order['id']) . "</p>";
echo "<p>Order Date: " . htmlspecialchars($order['order_date']) . "</p>";
echo "<p>Total Amount: UGX " . number_format($order['total_amount']) . "</p>";
echo "<p>Payment Method: " . htmlspecialchars($order['payment_method']) . "</p>";

// Optionally, fetch items
$items = $conn->query("SELECT * FROM pending_deliveries WHERE user_id = $user_id AND cart_id IN (SELECT id FROM cart)");
if ($items->num_rows > 0) {
    echo "<h3>Items:</h3><ul>";
    while ($item = $items->fetch_assoc()) {
        echo "<li>" . htmlspecialchars($item['product_name']) . " x" . $item['quantity'] . " - UGX " . number_format($item['amount']) . "</li>";
    }
    echo "</ul>";
}
?>
