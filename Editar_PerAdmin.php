<?php
session_start();
include("Conexion.php");

/*VALIDAR SESIÓN */
if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$vet_id = $_SESSION['veterinario_id'];

/* OBTENER DATOS ACTUALES */
$sql = "SELECT * FROM veterinarios WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $vet_id);
$stmt->execute();
$result = $stmt->get_result();
$vet = $result->fetch_assoc();

/* GUARDAR CAMBIOS */
if (isset($_POST['guardar'])) {

    $establecimiento = $_POST['establecimiento'];
    $telefono = $_POST['telefono'];
    $calle = $_POST['calle'];
    $numero_exterior = $_POST['numero_exterior'];
    $numero_interior = $_POST['numero_interior'];
    $colonia = $_POST['colonia'];
    $ciudad = $_POST['ciudad'];
    $estado = $_POST['estado'];
    $codigo_postal = $_POST['codigo_postal'];
    $horario = $_POST['horario'];
    $descripcion = $_POST['descripcion'];

    /* 🔥 NUEVOS CAMPOS */
    $dias_trabajo = isset($_POST['dias_trabajo']) 
        ? implode(",", $_POST['dias_trabajo']) 
        : "";

    // ✅ AQUÍ ESTÁ LA CLAVE
    $hora_inicio = !empty($_POST['hora_inicio']) 
    ? date("H:i:s", strtotime($_POST['hora_inicio'])) 
    : "08:00:00";

    $hora_fin = !empty($_POST['hora_fin']) 
        ? date("H:i:s", strtotime($_POST['hora_fin'])) 
        : "19:00:00";

    $citas_por_dia = $_POST['citas_por_dia'];
    $citas_por_hora = $_POST['citas_por_hora'];

    $ruta_imagen = $vet['imagen']; 
    
    //Imagen mantener si no cambia
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] == 0) {

        $carpeta = "img/perfilveterinario/";

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $nombreArchivo = time() . "_" . basename($_FILES["imagen"]["name"]);
        $rutaDestino = $carpeta . $nombreArchivo;

        if (move_uploaded_file($_FILES["imagen"]["tmp_name"], $rutaDestino)) {
            $ruta_imagen = $rutaDestino;
        }
    }

    $update = "UPDATE veterinarios SET 
        establecimiento=?, telefono=?, calle=?, numero_exterior=?, numero_interior=?, 
        colonia=?, ciudad=?, estado=?, codigo_postal=?, horario=?, imagen=?,
        descripcion=?,
        dias_trabajo=?, hora_inicio=?, hora_fin=?, citas_por_dia=?, citas_por_hora=?
        WHERE id=?";

    $stmt2 = $conn->prepare($update);
    $stmt2->bind_param(
        "sssssssssssssssiii",
        $establecimiento,
        $telefono,
        $calle,
        $numero_exterior,
        $numero_interior,
        $colonia,
        $ciudad,
        $estado,
        $codigo_postal,
        $horario,
        $ruta_imagen,
        $descripcion,
        $dias_trabajo,
        $hora_inicio,
        $hora_fin,
        $citas_por_dia,
        $citas_por_hora,
        $vet_id
    );

    if ($stmt2->execute()) {
        echo "<script>alert('✅ Datos actualizados'); window.location='PerfilAdmin.php';</script>";
        exit();
    } else {
        echo "<script>alert('❌ Error al guardar');</script>";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Perfil Admin</title>
    <link rel="stylesheet" href="CSS/Editar_PerAdmin.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
</head>
<body>

<h1>Editar Perfil</h1>

<form method="POST" enctype="multipart/form-data">

<div class="Contenido">

    <!-- IZQUIERDA -->
    <div class="lado-izq">

        <!-- IMAGEN PERFIL -->
        <img class="img-perfil" 
        src="<?php echo (!empty($vet['imagen']) && file_exists($vet['imagen'])) 
        ? $vet['imagen'] 
        : 'img/Perfil_Usuario.png'; ?>" 
        alt="Perfil">

        <!-- BOTÓN EDITAR -->
        <a href="#" onclick="document.getElementById('inputImagen').click(); return false;">
            <img class="img-edit" src="img/Perfil_Editar.png" alt="Agregar Foto">
        </a>
        <!-- INPUT OCULTO -->
        <input type="file" name="imagen" id="inputImagen" style="display:none;" accept="image/*">

    </div>

    <!-- DERECHA -->
    <div class="datos">

        <label class="dat">Nombre del establecimiento:</label>
        <input class="input-cont" type="text" name="establecimiento"
        value="<?php echo htmlspecialchars($vet['establecimiento']); ?>">

        <label class="dat">Teléfono:</label>
        <input class="input-cont" type="text" name="telefono"
        value="<?php echo htmlspecialchars($vet['telefono']); ?>">

        <label class="dat">Calle:</label>
        <input class="input-cont" type="text" name="calle"
        value="<?php echo htmlspecialchars($vet['calle']); ?>">

        <label class="dat">Número exterior:</label>
        <input class="input-cont" type="text" name="numero_exterior"
        value="<?php echo htmlspecialchars($vet['numero_exterior']); ?>">

        <label class="dat">Número interior:</label>
        <input class="input-cont" type="text" name="numero_interior"
        value="<?php echo htmlspecialchars($vet['numero_interior']); ?>">

        <label class="dat">Colonia:</label>
        <input class="input-cont" type="text" name="colonia"
        value="<?php echo htmlspecialchars($vet['colonia']); ?>">

        <label class="dat">Ciudad:</label>
        <input class="input-cont" type="text" name="ciudad"
        value="<?php echo htmlspecialchars($vet['ciudad']); ?>">

        <label class="dat">Estado:</label>
        <input class="input-cont" type="text" name="estado"
        value="<?php echo htmlspecialchars($vet['estado']); ?>">

        <label class="dat">Código postal:</label>
        <input class="input-cont" type="text" name="codigo_postal"
        value="<?php echo htmlspecialchars($vet['codigo_postal']); ?>">

        <label class="dat">Horario(Perfil):</label>
        <input class="input-cont" type="text" name="horario"
        value="<?php echo htmlspecialchars($vet['horario']); ?>">

        <label class="dat">Descripción del establecimiento:</label>
        <textarea class="input-cont" name="descripcion" rows="4" placeholder="Describe tu veterinaria..." maxlength="500"><?php echo htmlspecialchars($vet['descripcion'] ?? ''); ?></textarea>



        <label class="dat">Días de trabajo:</label>
        <div class="dias-semana">

        <?php
        $diasSeleccionados = isset($vet['dias_trabajo']) ? explode(",", $vet['dias_trabajo']) : [];
        $dias = [
            "Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"
        ];

        foreach ($dias as $dia) {
            $checked = in_array($dia, $diasSeleccionados) ? "checked" : "";
            echo '<label class="dia-check">
                    <input class="input-cont" type="checkbox" name="dias_trabajo[]" value="'.$dia.'" '.$checked.'>
                    '.$dia.'
                </label>';
        }
        ?>

        </div>

        <label class="dat">Hora inicio:</label>
        <input class="input-cont" type="time" name="hora_inicio"
        value="<?php echo $vet['hora_inicio'] ?? ''; ?>">

        <label class="dat">Hora fin:</label>
        <input class="input-cont" type="time" name="hora_fin"
        value="<?php echo !empty($vet['hora_fin']) ? substr($vet['hora_fin'],0,5) : ''; ?>">

        <label class="dat">Citas por día:</label>
        <input class="input-cont" type="number" name="citas_por_dia"
        value="<?php echo $vet['citas_por_dia'] ?? 30; ?>">

        <label class="dat">Citas por hora:</label>
        <input class="input-cont" type="number" name="citas_por_hora"
        value="<?php echo $vet['citas_por_hora'] ?? 10; ?>">

    </div>

</div>

<!-- BOTÓN -->
<button type="submit" name="guardar" class="btn-guardar">
    Guardar cambios
</button>

<a href="PerfilAdmin.php">
    <img class="btn-servicio" src="img/Regresar.png" alt="Regresar">
</a>

</form>

<!-- PREVISUALIZACION IMAGEN-->
<script>
document.getElementById("inputImagen").addEventListener("change", function(e){
    let file = e.target.files[0];
    if(file){
        let reader = new FileReader();
        reader.onload = function(e){
            document.querySelector(".img-perfil").src = e.target.result;
        }
        reader.readAsDataURL(file);
    }
});
</script>

</body>
</html>