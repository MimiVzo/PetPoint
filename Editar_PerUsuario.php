<?php
session_start();
include("Conexion.php");

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$usuario_id = $_SESSION['usuario_id'];

// Obtener datos actuales
$sql = "SELECT nombre, correo, telefono, imagen FROM usuarios WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$usuario = $result->fetch_assoc();

/* FOTO PERFIL */
$fotoPerfil = "img/Perfil_Usuario.png";

if (!empty($usuario['imagen'])) {

    $rutaServidor = __DIR__ . "/" . $usuario['imagen'];

    if (file_exists($rutaServidor)) {
        $fotoPerfil = $usuario['imagen'];
    }
}

// Guardar cambios
if (isset($_POST['guardar'])) {
    $nombre = $_POST['nombre'];
    $telefono = $_POST['telefono'];
    $imagen_nombre = $usuario['imagen']; // mantener la actual

    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] == 0) {

        $carpeta = "img/perfilusuario/";
        $nombreArchivo = time() . "_" . basename($_FILES["imagen"]["name"]);
        $ruta = $carpeta . $nombreArchivo;

        if (move_uploaded_file($_FILES["imagen"]["tmp_name"], $ruta)) {
            $imagen_nombre = $ruta;
        }
    }

    $update = "UPDATE usuarios SET nombre = ?, telefono = ?, imagen = ? WHERE id = ?";
    $stmt2 = $conn->prepare($update);
    $stmt2->bind_param("sssi", $nombre, $telefono, $imagen_nombre, $usuario_id);

    if ($stmt2->execute()) {
        echo "<script>alert('✅ Datos actualizados'); window.location='PerfilUsuario.php';</script>";
        exit();
    } else {
        echo "<script>alert('❌ Error al actualizar');</script>";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="CSS/Editar_PerUsuario.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
    <title>Editar Perfil</title>
</head>
<body>
    <h1>Editar Perfil</h1>

    <form method="POST" enctype="multipart/form-data">
    <div class="Contenido">

        <!-- IZQUIERDA -->
        <div class="lado-izq">

            <img id="previewImg" class="img-perfil" src="<?php echo $fotoPerfil; ?>" alt="Perfil">

            <a href="#">
                <img class="img-edit" src="img/Perfil_Editar.png" alt="Editar">
            </a>

            <input type="file" name="imagen" accept="image/*" style="display:none;" id="inputImagen">

            <script>
            // abrir selector
            document.querySelector('.img-edit').addEventListener('click', function(e){
                    e.preventDefault();
                    document.getElementById('inputImagen').click();
                });
            // PREVISUALIZAR IMAGEN
            document.getElementById("inputImagen").addEventListener("change", function(e){

                const archivo = e.target.files[0];

                if (archivo) {
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        document.getElementById("previewImg").src = e.target.result;
                    }

                    reader.readAsDataURL(archivo);
                }
            });
            </script>

        </div>

        <!-- DERECHA -->
        <div class="datos">

            <!-- Nombre -->
            <input class="input-cont" type="text" name="nombre"
                value="<?php echo htmlspecialchars($usuario['nombre']); ?>"
                placeholder="Nombre de usuario">

            <br>

            <!-- Correo -->
            <label class="dat">Correo electrónico</label><br>
            <label class="usuario">
                <?php echo htmlspecialchars($usuario['correo']); ?>
            </label><br><br>

            <!-- Teléfono -->
            <input class="input-cont" type="text" name="telefono"
                value="<?php echo htmlspecialchars($usuario['telefono']); ?>"
                placeholder="Teléfono">

        </div>
    </div>

    <button type="submit" name="guardar" class="btn-guardar">
        Guardar cambios
    </button>

    <a href="PerfilUsuario.php" class="btn-servicio">
    <img src="img/Regresar.png">
    </a>
</form>
</body>
</html>