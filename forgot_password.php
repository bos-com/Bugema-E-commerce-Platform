<?php
include 'db_connect.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $conn->real_escape_string($_POST['email']);
    $sql = "SELECT * FROM users WHERE email='$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $token = bin2hex(random_bytes(50)); // generate reset token
        $expiry = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $conn->query("UPDATE users SET reset_token='$token', reset_expires='$expiry' WHERE email='$email'");

        $resetLink = "http://localhost/yourproject/reset_password.php?token=$token"; // change domain as needed

        // Send reset email (you can replace this with PHPMailer for production)
        mail($email, "Password Reset - Bugema CampusShop", 
        "Click this link to reset your password: $resetLink");

        $message = "A password reset link has been sent to your email.";
    } else {
        $error = "No account found with that email.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password - Bugema CampusShop</title>
<style>
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f3f4f6;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
    }
    .container {
        background: #fff;
        padding: 2rem;
        border-radius: 10px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        width: 400px;
        text-align: center;
    }
    input {
        width: 90%;
        padding: 10px;
        margin: 1rem 0;
        border: 1px solid #ccc;
        border-radius: 5px;
    }
    button {
        width: 90%;
        padding: 10px;
        background: #091bbe;
        color: #fff;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }
    button:hover {
        background: #1059b9;
    }
    .message {
        color: green;
    }
    .error {
        color: red;
    }
</style>
</head>
<body>
<div class="container">
    <h2>Forgot Password</h2>
    <?php if (isset($message)) echo "<p class='message'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <form method="POST" action="">
        <input type="email" name="email" placeholder="Enter your email" required>
        <button type="submit">Send Reset Link</button>
    </form>
    <p><a href="login.php">Back to Login</a></p>
</div>
</body>
</html>
