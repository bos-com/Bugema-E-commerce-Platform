<?php
session_start();
include 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $conn->real_escape_string($_POST['role']);

    // ✅ Backend email format validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        if (!in_array($role, ['student', 'lecturer', 'admin'])) {
            $error = "Invalid role selected.";
        } else {
            $sql = "SELECT id FROM users WHERE username = '$username' OR email = '$email'";
            $result = $conn->query($sql);

            if ($result->num_rows > 0) {
                $error = "Username or email already exists.";
            } else {
                $sql = "INSERT INTO users (username, email, password, role) 
                        VALUES ('$username', '$email', '$hashed_password', '$role')";
                if ($conn->query($sql)) {
                    $_SESSION['user_id'] = $conn->insert_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $role;
                    header("Location: login.php");
                    exit;
                } else {
                    $error = "Error creating account.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register - Bugema CampusShop</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --primary-green: #091bbeff;
    --secondary-green: #4591e7ff;
    --accent-yellow: #facc15;
    --light-gray: #f3f4f6;
    --dark-gray: #111827;
    --text-gray: #4b5563;
    --white: #ffffff;
    --error-red: #dc2626;
    --success-green: #16a34a;
}
body {
    font-family: 'Poppins', sans-serif;
    color: var(--dark-gray);
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    background: var(--light-gray);
}

.login-wrapper {
    display: flex;
    width: 100%;
    max-width: 1200px;
    background: var(--white);
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    overflow: hidden;
    margin: 2rem 0;
}

.login-side {
    flex: 1;
    padding: 2rem 1.5rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
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
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.4);
    z-index: 1;
}

.image-side .caption {
    position: relative;
    z-index: 2;
    color: var(--white);
    text-align: center;
    padding: 1.5rem;
    background: rgba(9,27,190,0.7);
    border-radius: 10px;
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
    margin-bottom: 0.8rem;
    font-size: 1.5rem;
}

.form-group { 
    margin-bottom: 1rem; 
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

label {
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

/* Error and Success styles */
input.error-border { border: 2px solid var(--error-red); }
input.success-border { border: 2px solid var(--success-green); }

#password-error, #email-error {
    color: var(--error-red);
    font-size: 0.85rem;
    margin-top: 5px;
    display: none;
}

#email-success {
    color: var(--success-green);
    font-size: 0.85rem;
    margin-top: 5px;
    display: none;
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
    background: rgba(220,38,38,0.1);
    border-radius: 4px;
}

.login-link {
    text-align: center;
    margin-top: 1rem;
    font-size: 0.9rem;
}

.login-link a {
    color: red;
    text-decoration: none;
    font-weight: 500;
}

.login-link a:hover { text-decoration: underline; }

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
    .login-wrapper { flex-direction: column; margin: 1rem; }
    .image-side { height: 250px; }
    .eye-icon { right: 40px; }
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
        <h2>Register</h2>

        <?php if (isset($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form method="POST" action="register.php" onsubmit="return validateForm()">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" required>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input 
                    type="email" 
                    name="email" 
                    id="email" 
                    required 
                    pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                >
                <small id="email-error">Invalid email format.</small>
                <small id="email-success">✔ Valid email format.</small>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required>
                <img src="images/eye-close.png" class="eye-icon" id="togglePassword">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" required>
                <img src="images/eye-close.png" class="eye-icon" id="toggleConfirmPassword">
                <small id="password-error">Passwords do not match.</small>
            </div>

            <div class="form-group">
                <label for="role">Role</label>
                <select name="role" id="role" required>
                    <option value="student">Student</option>
                    <option value="lecturer">Lecturer</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <button type="submit">Register</button>

            <div class="social-login">
                <p>OR</p>
                <div class="social-buttons">
                    <a href="#"><img src="images/facebook.png" alt="Facebook Login"></a>
                    <a href="#"><img src="images/xicon.png" alt="X Login"></a>
                    <a href="#"><img src="images/instagram.png" alt="Instagram Login"></a>
                </div>
            </div>
        </form>

        <div class="login-link">
            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>

    <div class="image-side">
        <div class="caption">Explore Campus Shopping!</div>
    </div>
</div>

<script>
// Password visibility toggle
const togglePassword = document.getElementById("togglePassword");
const passwordField = document.getElementById("password");
const toggleConfirmPassword = document.getElementById("toggleConfirmPassword");
const confirmPasswordField = document.getElementById("confirm_password");
const emailField = document.getElementById("email");
const emailError = document.getElementById("email-error");
const emailSuccess = document.getElementById("email-success");

togglePassword.addEventListener("click", () => {
    const type = passwordField.type === "password" ? "text" : "password";
    passwordField.type = type;
    togglePassword.src = type === "password" ? "images/eye-close.png" : "images/eye-open.png";
});

toggleConfirmPassword.addEventListener("click", () => {
    const type = confirmPasswordField.type === "password" ? "text" : "password";
    confirmPasswordField.type = type;
    toggleConfirmPassword.src = type === "password" ? "images/eye-close.png" : "images/eye-open.png";
});

// Password match validation
const errorMsg = document.getElementById("password-error");
function checkPasswords() {
    if (confirmPasswordField.value === "") {
        confirmPasswordField.classList.remove("error-border");
        errorMsg.style.display = "none";
        return;
    }
    if (passwordField.value !== confirmPasswordField.value) {
        confirmPasswordField.classList.add("error-border");
        errorMsg.style.display = "block";
    } else {
        confirmPasswordField.classList.remove("error-border");
        errorMsg.style.display = "none";
    }
}
passwordField.addEventListener("input", checkPasswords);
confirmPasswordField.addEventListener("input", checkPasswords);

// ✅ Email validation
function validateEmail(email) {
    const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/;
    return emailPattern.test(email);
}

emailField.addEventListener("input", () => {
    if (!validateEmail(emailField.value)) {
        emailError.style.display = "block";
        emailSuccess.style.display = "none";
        emailField.classList.add("error-border");
        emailField.classList.remove("success-border");
    } else {
        emailError.style.display = "none";
        emailSuccess.style.display = "block";
        emailField.classList.remove("error-border");
        emailField.classList.add("success-border");
    }
});

// ✅ Final form validation before submission
function validateForm() {
    if (!validateEmail(emailField.value)) {
        emailError.style.display = "block";
        emailField.focus();
        return false;
    }
    if (passwordField.value !== confirmPasswordField.value) {
        errorMsg.style.display = "block";
        confirmPasswordField.focus();
        return false;
    }
    return true;
}
</script>
</body>
</html>

<?php
$conn->close();
?>
