<?php
include("Conexion.php");
session_start();

if (isset($_POST['entrar'])) {
    $correo = $_POST['correo'];
    $contrasena = $_POST['contrasena'];

    //Buscar primero en veterinarios
    $sql_vet = "SELECT * FROM veterinarios WHERE correo = '$correo'";
    $result_vet = $conn->query($sql_vet);

    if ($result_vet->num_rows > 0) {
        $vet = $result_vet->fetch_assoc();

        if (password_verify($contrasena, $vet['contrasena']) || $contrasena === $vet['contrasena']) {

            //Verificar si la cuenta está activa
            if ($vet['is_active'] == 0) {
                echo "<script>
                    alert('⚠️ Tu cuenta de veterinario aún no ha sido activada. Revisa tu correo electrónico para activarla.');
                    window.location.href = 'iniciosesion.php';
                </script>";
                exit();
            }

            $_SESSION['veterinario_id'] = $vet['id'];
            $_SESSION['tipo'] = 'veterinario';
            header("Location: ServiciosAdmin.html");
            exit();

        } else {
            echo "<script>alert('❌ Contraseña incorrecta');</script>";
        }

    } else {
        // Buscar en usuarios
        $sql_user = "SELECT * FROM usuarios WHERE correo = '$correo'";
        $result_user = $conn->query($sql_user);

        if ($result_user->num_rows > 0) {
            $user = $result_user->fetch_assoc();

            if (password_verify($contrasena, $user['contrasena']) || $contrasena === $user['contrasena']) {

                //Verificar si la cuenta está activa
                if ($user['is_active'] == 0) {
                    echo "<script>
                        alert('⚠️ Tu cuenta aún no ha sido activada. Revisa tu correo electrónico para activarla.');
                        window.location.href = 'iniciosesion.php';
                    </script>";
                    exit();
                }

                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['tipo'] = 'usuario';
                header("Location: servicios.html");
                exit();

            } else {
                echo "<script>alert('❌ Contraseña incorrecta');</script>";
            }
        } else {
            echo "<script>alert('❌ No existe ninguna cuenta con ese correo.');</script>";
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="CSS/iniciosesion.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
    <title>Iniciar sesión</title>
</head>
<body>
    <div class="content">
        <h1>Inicia sesión</h1>

        <form class="div-input" method="POST" id="loginForm">
            <input class="input-cont" type="email" name="correo" required placeholder="Correo electrónico">
            <input class="input-cont" type="password" name="contrasena" required placeholder="Contraseña">
        </form>

        <button class="btn-entrar" form="loginForm" type="submit" name="entrar">Entrar</button>
        <br>

        <div class="linea"></div>

        <p class="regis-txt">
            ¿Eres nuevo?<br>
            Crea una cuenta aquí.
        </p>
        <button class="btn-registrar">
            <a href="Crearcuenta.php">Registrate</a>
        </button>
    </div>
</body>
</html>
