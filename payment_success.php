<?php
session_start();
if (!isset($_SESSION['payment_success'])) {
    header("Location: cart.php");
    exit();
}

$message = $_SESSION['payment_success'];
unset($_SESSION['payment_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - Bugema CampusShop</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
        .success { color: green; font-size: 24px; margin-bottom: 20px; }
        .btn { background: #091bbe; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="success">✓ Payment Successful!</div>
    <p><?php echo htmlspecialchars($message); ?></p>
    <a href="index.php" class="btn">Continue Shopping</a>
    <a href="cart.php" class="btn">Back to Cart</a>
</body>
</html>