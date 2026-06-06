<?php
session_start();
include("Conexion.php");

if (!isset($_SESSION['usuario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$sql = "SELECT nombre, apellido, correo, telefono, imagen FROM usuarios WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $usuario = $result->fetch_assoc();
    /* FOTO PERFIL */
    $fotoPerfil = "img/Perfil_Usuario.png";

    if (!empty($usuario['imagen'])) {

        $rutaServidor = __DIR__ . "/" . $usuario['imagen'];

        if (file_exists($rutaServidor)) {
            $fotoPerfil = $usuario['imagen'];
        }
    }
} else {
    echo "<script>alert('No se encontró información del usuario'); window.location='servicios.html';</script>";
    exit();
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil</title>
    <link rel="stylesheet" href="CSS/PerfilUsuario.css">
    <link rel="icon" href="img/Pet_Point.png">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
</head>

<body>

<h1>Perfil</h1>

<div class="Contenido">

    <!-- IZQUIERDA -->
    <div class="lado-izq">

        <img class="img-perfil" src="<?php echo $fotoPerfil; ?>" alt="Perfil">

        <a href="Editar_PerUsuario.php" class="btn-g">
            <button class="btn-guardar">Editar perfil</button>
        </a>

    </div>

    <!-- DERECHA -->
    <div class="datos">

        <label class="dat">Nombre de usuario</label><br>
        <label class="usuario">
            <?php echo htmlspecialchars($usuario['nombre']." ".$usuario['apellido']); ?>
        </label><br><br>

        <label class="dat">Correo electrónico</label><br>
        <label class="usuario">
            <?php echo htmlspecialchars($usuario['correo']); ?>
        </label><br><br>

        <label class="dat">Teléfono</label><br>
        <label class="usuario">
            <?php 
                if(!empty($usuario['telefono'])){
                    echo htmlspecialchars($usuario['telefono']);
                }else{
                    echo "Agregar número";
                } 
            ?>
        </label>

    </div>

</div>

<a href="servicios.html" class="btn-servicio">
    <img src="img/Registro_Regreso.png">
</a>

</body>
</html>