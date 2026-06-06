<?php
session_start();

if (isset($_POST['registrar'])) {
    include("conexion.php");

    $nombre = $_POST['Nombre'];
    $apellido = $_POST['Apellido'];
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $genero = $_POST['genero'];
    $correo = $_POST['correo'];
    $contrasena = password_hash($_POST['contrasena'], PASSWORD_DEFAULT);
    $establecimiento = $_POST['establecimiento'];

    $check = "SELECT * FROM veterinarios WHERE correo = '$correo'";
    $result = $conn->query($check);
    if ($result->num_rows > 0) {
        echo "<script>
            alert('⚠️ Este correo ya está registrado. Usa otro.');
            window.location.href = 'CrearCuentaVeteri.php';
        </script>";
        exit();
    }

    $activation_code = md5(uniqid(rand(), true));

    $sql = "INSERT INTO veterinarios (nombre, apellido, fecha_nacimiento, genero, correo, contrasena, establecimiento, activation_code, is_active)
            VALUES ('$nombre', '$apellido', '$fecha_nacimiento', '$genero', '$correo', '$contrasena', '$establecimiento', '$activation_code', 0)";

    if ($conn->query($sql) === TRUE) {
        $_SESSION['veterinario_id'] = $conn->insert_id; // Guardar id
        header('Location: CrearDatVete.php');
        exit();
    } else {
        echo "<script>alert('❌ Error: " . $conn->error . "');</script>";
    }

    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="CSS/CrearCuentaVeteri.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
    <title>Crear cuenta veterinaria</title>
</head>
<body>
    <div class="cont-crear">
        <h1>Crea tu cuenta</h1><br>
        <form class="div-crear" method="post">
            <div class="from-datos">
                <input class="datos" type="text" name="Nombre" placeholder="Nombre" required>
                <input class="datos" type="text" name="Apellido" placeholder="Apellido" required>
            </div>
            
            <h2>Fecha de nacimiento</h2>
            <div class="dat">
                <input class="date" type="date" name="fecha_nacimiento" required>
            </div>
            
            <h2>Género</h2>
            <div class="div-gnr">
                <div class="gen">Mujer <input type="radio" name="genero" value="Mujer" required></div>
                <div class="gen">Hombre <input type="radio" name="genero" value="Hombre"></div>
                <div class="gen">Otro <input type="radio" name="genero" value="Otro"></div>
            </div>
            
            <div class="div-input">
                <input class="input-cont" type="email" name="correo" required placeholder="Correo electrónico">
                <input class="input-cont" type="password" name="contrasena" required placeholder="Contraseña">
                <input class="input-cont" type="text" name="establecimiento" required placeholder="Nombre del establecimiento">
            </div>
            <button class="btn-entrar" type="submit" name="registrar">Siguiente</button>
        </form>
    </div>
</body>
</html>