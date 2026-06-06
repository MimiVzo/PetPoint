<?php
session_start();
include("conexion.php");

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['veterinario_id'])) {
    header("Location: CrearCuentaVeteri.php");
    exit();
}

$id = $_SESSION['veterinario_id'];

if (isset($_POST['registrar'])) {
    $calle = $_POST['calle'];
    $numero_exterior = $_POST['numero_exterior'];
    $numero_interior = $_POST['numero_interior'];
    $codigo_postal = $_POST['codigo_postal'];
    $colonia = $_POST['colonia'];
    $estado = $_POST['estado'];
    $ciudad = $_POST['ciudad'];

    
    $sql = "UPDATE veterinarios
            SET calle='$calle', numero_exterior='$numero_exterior', numero_interior='$numero_interior',
                codigo_postal='$codigo_postal', colonia='$colonia', estado='$estado', ciudad='$ciudad'
            WHERE id=$id";

    if ($conn->query($sql) === TRUE) {
        // Obtener datos del veterinario para enviar correo
        $query = "SELECT nombre, apellido, correo, activation_code FROM veterinarios WHERE id = $id";
        $result = $conn->query($query);
        $vet = $result->fetch_assoc();

        $nombre = $vet['nombre'];
        $apellido = $vet['apellido'];
        $correo = $vet['correo'];
        $activation_code = $vet['activation_code'];

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'juan.hernandez7738@gmail.com';
            $mail->Password = 'ppww outc iyzp joqh'; // Contraseña de aplicación
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('juan.hernandez7738@gmail.com', 'Pet Point');
            $mail->addAddress($correo, $nombre . ' ' . $apellido);
            $mail->isHTML(true);
            $mail->Subject = 'Activación de cuenta veterinaria - Pet Point';

            $activation_link = "http://localhost/Pet_Point/ActivarCuenta.php?code=" . $activation_code . "&tipo=veterinario";

            $mail->Body = "
                <h2>Hola, {$nombre} 🐾</h2>
                <p>Gracias por registrar tu veterinaria en <b>Pet Point</b>.</p>
                <p>Activa tu cuenta haciendo clic en el siguiente enlace:</p>
                <p><a href='{$activation_link}' target='_blank'>Activar mi cuenta</a></p>
                <br>
                <p>Si no realizaste este registro, ignora este mensaje.</p>
            ";

            $mail->send();

            unset($_SESSION['veterinario_id']);

            echo "<script>
                alert('✅ Tus datos se guardaron correctamente. Se ha enviado un correo de activación a $correo.');
                window.location.href = 'iniciosesion.php';
            </script>";

        } catch (Exception $e) {
            echo "<script>alert('⚠️ Error al enviar el correo: {$mail->ErrorInfo}');</script>";
        }
    } else {
        echo "<script>alert('❌ Error al guardar los datos: " . $conn->error . "');</script>";
    }

    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="CSS/CrearDatVete.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
    <title>Datos Veterinaria</title>
</head>
<body>
    <div class="cont-crear">
        <h1>Domicilio de la veterinaria</h1><br>
        <form class="div-crear" method="post">
            <h2>Domicilio</h2>
            <div class="from-datos">
                <input class="input-cont" type="text" name="calle" placeholder="Calle" required>
                <input class="datos" type="text" name="numero_exterior" placeholder="Numero exterior" required>
                <input class="datos" type="text" name="numero_interior" placeholder="Numero interior(opc)">
                <input class="input-cont" type="text" name="codigo_postal" placeholder="Codigo postal" required>
                <input class="input-cont" type="text" name="colonia" placeholder="Colonia" required>
                <input class="input-cont" type="text" name="estado" placeholder="Estado" required>
                <input class="input-cont" type="text" name="ciudad" placeholder="Ciudad" required>
            </div>

            <button class="btn-entrar" type="submit" name="registrar">Entrar</button>
        </form>
    </div>
</body>
</html>