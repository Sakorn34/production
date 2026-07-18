<?php
require __DIR__ . '/config.php';

$conn = db();

echo "<h3>Database : ";
$r = $conn->query("SELECT DATABASE() db");
echo $r->fetch_assoc()['db'];
echo "</h3>";

$r = $conn->query("SHOW TABLES");

while($t = $r->fetch_array()){
    echo $t[0]."<br>";
}