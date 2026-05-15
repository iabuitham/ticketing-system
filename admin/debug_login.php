<?php
require_once '../includes/db.php';
session_start();

$conn = getConnection();

echo "<h1>Login Debug</h1>";

// Test 1: Check if password_verify function exists
echo "<h3>Test 1: password_verify() function</h3>";
if (function_exists('password_verify')) {
    echo "✅ password_verify() exists<br>";
} else {
    echo "❌ password_verify() does NOT exist!<br>";
}

// Test 2: Get admin user
echo "<h3>Test 2: Admin user from database</h3>";
$result = $conn->query("SELECT * FROM admins WHERE username = 'admin'");
$admin = $result->fetch_assoc();

if ($admin) {
    echo "Username: " . $admin['username'] . "<br>";
    echo "Stored hash: " . $admin['password'] . "<br>";
    echo "Hash length: " . strlen($admin['password']) . "<br>";
} else {
    echo "❌ Admin user not found!<br>";
}

// Test 3: Test password_verify with manual hash
echo "<h3>Test 3: password_verify() test</h3>";
$test_password = 'admin123';
$test_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

if (password_verify($test_password, $test_hash)) {
    echo "✅ password_verify() works! The hash matches 'admin123'<br>";
} else {
    echo "❌ password_verify() failed!<br>";
}

// Test 4: Test with database hash
echo "<h3>Test 4: Test with database hash</h3>";
if ($admin && password_verify($test_password, $admin['password'])) {
    echo "✅ Database hash matches 'admin123'!<br>";
} else {
    echo "❌ Database hash does NOT match 'admin123'<br>";
    
    // Try to see what's wrong
    echo "<br>Attempting to fix...<br>";
    
    // Generate a new hash
    $new_hash = password_hash($test_password, PASSWORD_DEFAULT);
    echo "New hash for 'admin123': " . $new_hash . "<br>";
    echo "New hash length: " . strlen($new_hash) . "<br>";
    
    // Update the database
    $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE username = 'admin'");
    $stmt->bind_param("s", $new_hash);
    if ($stmt->execute()) {
        echo "✅ Database updated with new hash!<br>";
        
        // Test again
        if (password_verify($test_password, $new_hash)) {
            echo "✅ New hash works! Try logging in now.<br>";
        }
    }
}

// Show all users
echo "<h3>All Admin Users:</h3>";
$all = $conn->query("SELECT id, username, LEFT(password, 15) as hash_start, LENGTH(password) as len FROM admins");
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Username</th><th>Hash Start</th><th>Length</th></tr>";
while ($row = $all->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['username']}</td>";
    echo "<td>{$row['hash_start']}</td>";
    echo "<td>{$row['len']}</td>";
    echo "</tr>";
}
echo "</table>";

$conn->close();
?>