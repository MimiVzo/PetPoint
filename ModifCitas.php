<?php
session_start();
include("Conexion.php");

if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$id_vet = $_SESSION['veterinario_id'];

if (!isset($_GET['id'])) {
    header("Location: CitasAdmin.php");
    exit();
}

$id_cita = intval($_GET['id']);

$sql = "SELECT * FROM citas WHERE id = ? AND id_veterinaria = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $id_cita, $id_vet);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "❌ Cita no encontrada";
    exit();
}

$cita = $result->fetch_assoc();

if (isset($_POST['guardar'])) {

    $servicio = $_POST['servicio'];
    $fecha = $_POST['fecha'];
    $hora = $_POST['hora'];
    $telefono = $_POST['telefono'];

    $update = "UPDATE citas 
               SET servicio = ?, fecha = ?, hora = ?, telefono = ?
               WHERE id = ? AND id_veterinaria = ?";

    $stmt2 = $conn->prepare($update);
    $stmt2->bind_param("ssssii", $servicio, $fecha, $hora, $telefono, $id_cita, $id_vet);

    if ($stmt2->execute()) {
        echo "<script>alert('✅ Cita actualizada'); window.location='CitasAdmin.php';</script>";
        exit();
    } else {
        echo "<script>alert('❌ Error al actualizar');</script>";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="CSS/ModifCitas.css">
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
<title>Modificar Citas</title>
</head>

<body>

<div class="contenido">

    <div class="tabla">

        <h1>Editar citas</h1>

        <label class="mod-nom">
            Modificar cita de: <?php echo htmlspecialchars($cita['nombre_mascota']); ?>
        </label>

        <form method="POST" id="form-citas">

            <div class="modificar">

                <label class="servicio">Servicio</label>
                <input class="input-mod" type="text" name="servicio"
                value="<?php echo htmlspecialchars($cita['servicio']); ?>" required>

                <label class="servicio">Fecha y Hora</label>

                <div class="fila">
                    <input class="input-modi" type="date" name="fecha"
                    value="<?php echo $cita['fecha']; ?>" required>

                    <input class="input-modi" type="time" name="hora"
                    value="<?php echo $cita['hora']; ?>" required>
                </div>

                <label class="servicio">Contacto</label>
                <input class="input-mod" type="text" name="telefono"
                value="<?php echo htmlspecialchars($cita['telefono']); ?>" required>
            </div>
        </form>
    </div>
        <!-- BOTON CENTRADO -->
        <div class="contenedor-boton">
            <button type="submit" form="form-citas" name="guardar" class="btn-guardar">
                Guardar cambios
            </button>
        </div>

        <a href="CitasAdmin.php" class="btn-servicio">
            <img src="img/Regresar.png">
        </a>
</div>

</body>
</html>