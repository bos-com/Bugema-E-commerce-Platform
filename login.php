<?php
session_start();
include 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $sql = "SELECT id, username, password, role FROM users WHERE username = '$username'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Transfer guest cart to logged-in user's cart
            if (isset($_SESSION['guest_cart']) && !empty($_SESSION['guest_cart']) && isset($_SESSION['user_id'])) {
                $user_id = $_SESSION['user_id'];
                $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
                foreach ($_SESSION['guest_cart'] as $product_id => $quantity) {
                    $stmt->bind_param("iiii", $user_id, $product_id, $quantity, $quantity);
                    $stmt->execute();
                }
                $stmt->close();
                $_SESSION['guest_cart'] = []; // Clear guest cart after transfer
            }

            // Redirect based on role
            if ($user['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: cart.php"); // Redirect to cart.php to show transferred items
            }
            exit;
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "No user found with that username.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Bugema CampusShop</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
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
            color: var(--dark-gray);
            line-height: 1.6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: hidden;
        }

        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 1200px;
            height: 80vh;
            background: var(--white);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .login-side {
            flex: 1;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--white);
            text-align: center;
        }

        .image-side {
            flex: 1;
            background: url('images/side.jpg') no-repeat center center/cover;
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .image-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }

        .image-side .caption {
            position: relative;
            z-index: 2;
            color: var(--white);
            text-align: center;
            padding: 1.5rem;
            border-radius: 10px;
            background: rgba(9, 27, 190, 0.7);
            font-size: 1.5rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .logo img {
            height: 60px;
            width: 60px;
            border-radius: 50%;
            object-fit: cover;
            padding: 5px;
        }

        .logo span {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-left: 1rem;
        }

        h2 {
            color: var(--primary-green);
            text-align: center;
            margin-bottom: 1rem;
            font-size: 1.8rem;
        }

        .form-group { 
            margin-bottom: 1rem; 
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-gray);
            align-self: flex-start;
            margin-left: 60px;
        }

        input, select {
            width: 80%;
            padding: 12px;
            border: 1px solid var(--text-gray);
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input:focus, select:focus {
            border-color: var(--primary-green);
            outline: none;
            box-shadow: 0 0 5px rgba(9, 27, 190, 0.3);
        }
        .eye-icon {
            position: absolute;
            right: 70px;
            top: 44px;
            cursor: pointer;
            width: 25px;
            height: 25px;
            opacity: 0.7;
        }

        button {
            width: 80%;
            padding: 12px;
            background: var(--accent-yellow);
            color: var(--dark-gray);
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease, transform 0.2s ease;
        }

        button:hover {
            background: var(--secondary-green);
            color: var(--white);
            transform: translateY(-2px);
        }

        .error {
            color: var(--error-red);
            text-align: center;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            padding: 0.5rem;
            background: rgba(220, 38, 38, 0.1);
            border-radius: 4px;
        }

        .register-link {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.9rem;
        }

        .register-link a {
            color: red;
            text-decoration: none;
            font-weight: 500;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* Social Login Buttons */
        .social-login {
            margin-top: 1rem;
            text-align: center;
        }

        .social-login p {
            color: var(--text-gray);
            margin-bottom: 0.5rem;
        }

        .social-buttons a {
            display: inline-block;
            margin: 0 10px;
            transition: transform 0.2s ease;
        }

        .social-buttons a img {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
        }

        .social-buttons a:hover {
            transform: scale(1.1);
        }

        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
                height: auto;
                max-width: 100%;
                margin: 1rem;
            }

            .login-side, .image-side {
                flex: 1 1 100%;
                height: 400px;
            }

            .logo img {
                height: 50px;
                width: 50px;
            }

            .logo span {
                font-size: 1.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            input, button {
                font-size: 0.9rem;
            }

            .image-side .caption {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 480px) {
            .login-wrapper {
                margin: 0.5rem;
            }

            .login-side, .image-side {
                height: 300px;
            }

            .logo img {
                height: 40px;
                width: 40px;
            }

            .logo span {
                font-size: 1.2rem;
            }

            h2 {
                font-size: 1.3rem;
            }

            input, button {
                font-size: 0.8rem;
            }

            .image-side .caption {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-side">
            <div class="logo">
                <img src="images/download.png" alt="Bugema CampusShop Logo">
                <span>Bugema CampusShop</span>
            </div>
            <h2>Login</h2>
            <?php if (isset($error)): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" required >
                    <img src="images/eye-close.png" alt="Toggle" class="eye-icon" id="togglePassword">
                </div>
                <button type="submit">Login</button>

                <!-- Social Login Section -->
                <div class="social-login">
                    <p>OR</p>
                    <div class="social-buttons">
                        <a href="#"><img src="images/facebook.png" alt="Facebook Login"></a>
                        <a href="#"><img src="images/xicon.png" alt="X Login"></a>
                        <a href="#"><img src="images/instagram.png" alt="Instagram Login"></a>
                    </div>
                </div>
            </form>

            <div class="register-link">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
                <p><a href="forgot_password.php" style="color: var(--primary-green); text-decoration: none;">Forgot Password?</a></p>
            </div>
        </div>

        <div class="image-side">
            <div class="caption">Explore Campus Shopping!</div>
        </div>
    </div>
</body>
</html>
<script>
    // Show/Hide Password for both fields
        const togglePassword = document.getElementById("togglePassword");
        const passwordField = document.getElementById("password");

        togglePassword.addEventListener("click", () => {
            const type = passwordField.type === "password" ? "text" : "password";
            passwordField.type = type;
            togglePassword.src = type === "password" ? "images/eye-close.png" : "images/eye-open.png";
        });
</script>

<?php
$conn->close();
?>
