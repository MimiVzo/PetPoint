<?php
session_start();
include("Conexion.php");

/* VALIDAR SESIÓN */
if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$vet_id = $_SESSION['veterinario_id'];

/* OBTENER DATOS */
$sql = "SELECT * FROM veterinarios WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $vet_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $vet = $result->fetch_assoc();
} else {
    echo "<script>alert('No se encontró el establecimiento'); window.location='ServiciosAdmin.html';</script>";
    exit();
}

/* FOTO PERFIL */
$fotoPerfil = "img/Perfil_Usuario.png";

if (!empty($vet['imagen'])) {

    $rutaServidor = __DIR__ . "/" . $vet['imagen'];
    $rutaWeb = $vet['imagen'];

    if (file_exists($rutaServidor)) {
        $fotoPerfil = $rutaWeb;
    }
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil Admin</title>
    <link rel="stylesheet" href="CSS/PerfilAdmin.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png">
</head>

<body>

<div class="Contenido">

    <!-- IZQUIERDA -->
    <div class="lado-izq">
        <h1>Perfil</h1>

        <!-- FOTO -->
        <img class="img-perfil" src="<?php echo $fotoPerfil; ?>" alt="Perfil">

        <!-- Correo -->
        <label class="usuario usuaper">
            <?php echo htmlspecialchars($vet['correo']); ?>
        </label><br><br>


        <!-- boton editar -->
        <a href="Editar_PerAdmin.php" class="btn-g">
                <button class="btn-Edit">Editar perfil</button>
        </a>
    </div>

    <!-- DERECHA -->
    <div class="datos">

        <!-- Nombre -->
        <label class="dat">Nombre del establecimiento</label><br>
        <label class="usuario">
            <?php echo htmlspecialchars($vet['establecimiento']); ?>
        </label><br><br>

        <!-- Teléfono -->
        <label class="dat">Teléfono</label><br>
        <label class="usuario">
            <?php echo htmlspecialchars($vet['telefono']); ?>
        </label><br><br>

        <!-- Dirección -->
        <label class="dat">Dirección</label><br>
        <label class="usuario">
            <?php 
            echo htmlspecialchars($vet['calle']) . " #" . htmlspecialchars($vet['numero_exterior']);

            if(!empty($vet['numero_interior'])){
                echo " Int. " . htmlspecialchars($vet['numero_interior']);
            }

            echo ", " . htmlspecialchars($vet['colonia']) . ", " .
                 htmlspecialchars($vet['ciudad']) . ", " .
                 htmlspecialchars($vet['estado']) . ", CP " .
                 htmlspecialchars($vet['codigo_postal']);
            ?>
        </label><br><br>

        <!-- Horario -->
        <label class="dat">Horario</label><br>
        <label class="usuario">
            <?php 
                if(!empty($vet['horario'])){
                    echo htmlspecialchars($vet['horario']);
                } else {
                    echo "No definido";
                }
            ?>
        </label><br><br>

        <!-- descripcion -->
        <label class="dat">Descripción</label><br>
        <label class="usuario">
            <?php 
                if(!empty($vet['descripcion'])){
                    echo htmlspecialchars($vet['descripcion']);
                } else {
                    echo "No hay descripción";
                }
            ?>
        </label><br><br>

</div>

<a href="ServiciosAdmin.html" class="btn-servicio">
    <img src="img/Registro_Regreso.png">
</a>

</body>
</html>