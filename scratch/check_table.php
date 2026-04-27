<?php
require 'db.php';
$res = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($res->num_rows > 0) {
    echo "Table 'attendance' exists.\n";
    $res = $conn->query("DESCRIBE attendance");
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Table 'attendance' does not exist.\n";
}
?>
