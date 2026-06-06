<?php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "pet_point";

/* =========================
   CONEXIÓN MYSQLI (tu actual)
========================= */
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Error en la conexión MySQLi: " . $conn->connect_error);
}

/* =========================
   CONEXIÓN PDO (nueva)
========================= */
try {
    $pdo = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Error en la conexión PDO: " . $e->getMessage());
}
?>