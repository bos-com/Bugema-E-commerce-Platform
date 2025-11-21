<?php
session_start();
include 'db_connect.php';

if (isset($_GET['query'])) {
    $query = $conn->real_escape_string($_GET['query']);
    $type = $_GET['type'] ?? 'full';

    if ($type === 'autocomplete') {
        $sql = "SELECT name FROM products WHERE name LIKE '%$query%' LIMIT 5";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            echo "<ul>";
            while ($row = $result->fetch_assoc()) {
                echo "<li class='suggestion'>" . htmlspecialchars($row['name']) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<div class='no-results'>No suggestions found</div>";
        }
    } else {
        $sql = "SELECT * FROM products WHERE name LIKE '%$query%'";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $image_path = !empty($row['image_path']) ? htmlspecialchars($row['image_path']) : 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+A8AAQAB3gB4cAAAAABJRU5ErkJggg==';
                echo "<div class='product-card'>";
                echo "<img src='$image_path' alt='" . htmlspecialchars($row['name']) . "'>";
                echo "<h4>" . htmlspecialchars($row['name']) . "</h4>";
                echo "<p>Price: UGX " . number_format($row['price']) . "</p>";
                if (isset($_SESSION['user_id'])) {
                    echo "<form method='POST' action='cart.php'>";
                    echo "<input type='hidden' name='product_id' value='" . $row['id'] . "'>";
                    echo "<button type='submit' name='add_to_cart'>🛒</button>";
                    echo "</form>";
                } else {
                    echo "<p><a href='login.php' class='login-link'>Login to add to cart</a></p>";
                }
                echo "</div>";
            }
        } else {
            echo "<div class='no-results'>No results found</div>";
        }
    }
}

$conn->close();
?>