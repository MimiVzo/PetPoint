<?php
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_POST['registrar'])) {
    include("conexion.php");

    $nombre = $_POST['Nombre'];
    $apellido = $_POST['Apellido'];
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $genero = $_POST['genero'];
    $correo = $_POST['correo'];
    $contrasena = password_hash($_POST['contrasena'], PASSWORD_DEFAULT);

    // Verificar si ya existe el correo
    $check = "SELECT * FROM usuarios WHERE correo = '$correo'";
    $result = $conn->query($check);
    if ($result->num_rows > 0) {
        echo "<script>
            alert('⚠️ El correo ya está registrado. Intenta con otro.');
            window.location.href = 'CrearCuenta.php';
        </script>";
        exit();
    }
    // Generar código de activación
    $activation_code = md5(uniqid(rand(), true));
    // Insertar cuenta inactiva
    $sql = "INSERT INTO usuarios (nombre, apellido, fecha_nacimiento, genero, correo, contrasena, activation_code, is_active)
            VALUES ('$nombre', '$apellido', '$fecha_nacimiento', '$genero', '$correo', '$contrasena', '$activation_code', 0)";

    if ($conn->query($sql) === TRUE) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'juan.hernandez7738@gmail.com';
            $mail->Password = 'ppww outc iyzp joqh'; 
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('juan.hernandez7738@gmail.com', 'Pet Point');
            $mail->addAddress($correo, $nombre . ' ' . $apellido);
            $mail->isHTML(true);
            $mail->Subject = 'Activa tu cuenta en Pet Point';

            $activation_link = "http://localhost/Pet_Point/ActivarCuenta.php?code=" . $activation_code;

            $mail->Body = "
                <h2>¡Hola, {$nombre}!</h2>
                <p>Gracias por registrarte en <b>Pet Point</b>.</p>
                <p>Activa tu cuenta haciendo clic aquí:</p>
                <p><a href='{$activation_link}' target='_blank'>Activar mi cuenta</a></p>
                <br><p>Si no creaste esta cuenta, ignora este mensaje.</p>
            ";

            if ($mail->send()) {
                echo "<script>
                    alert('✅ Registro exitoso. Se envió un correo de activación a $correo.');
                    window.location.href = 'iniciosesion.php';
                </script>";
            } else {
                echo "<script>alert('⚠️ Error al enviar el correo: " . $mail->ErrorInfo . "');</script>";
            }
        } catch (Exception $e) {
            echo "<script>alert('⚠️ Error al enviar el correo: {$mail->ErrorInfo}');</script>";
        }
    } else {
        echo "<script>alert('❌ Error al registrar: " . $conn->error . "');</script>";
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="CSS/Crearcuenta.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
    <title>Crear cuenta</title>
</head>
<body>
    <div class="registro">
        <button class="regis"><a href="CrearCuentaVeteri.php"><img height="100px" width="100px" src="img/Img_RegisVete.png" alt="RegistrarVet">Regristrar tu veterinaria</a></button>
    </div>
    <div class="cont-crear">
        <h1>Crea tu cuenta</h1><br>

        <form class="div-crear" method="post">
            <div class="from-datos">
                <input class="datos" type="text" name="Nombre" placeholder="Nombre" required>
                <input class="datos" type="text" name="Apellido" placeholder="Apellido" required>
            </div>
            
            <h2>Fecha de nacimiento</h2>
            <div class="from-date">
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
            </div>
            <button class="btn-entrar" type="submit" name="registrar">Entrar</button>
        </form>
    </div>
</body>
</html>
