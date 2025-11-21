<?php
include 'db_connect.php';
session_start();

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $sql = "SELECT * FROM users WHERE reset_token='$token' AND reset_expires > NOW()";
    $result = $conn->query($sql);

    if ($result->num_rows === 0) {
        die("Invalid or expired token.");
    }
} else {
    die("No token provided.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $conn->query("UPDATE users SET password='$new_password', reset_token=NULL, reset_expires=NULL WHERE reset_token='$token'");
    echo "<p>Password successfully reset. <a href='login.php'>Login here</a>.</p>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Password - Bugema CampusShop</title>
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
</style>
</head>
<body>
<div class="container">
    <h2>Reset Password</h2>
    <form method="POST" action="">
        <input type="password" name="password" placeholder="Enter new password" required>
        <button type="submit">Reset Password</button>
    </form>
</div>
</body>
</html>
