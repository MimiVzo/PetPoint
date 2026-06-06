<?php
session_start();
date_default_timezone_set("America/Mexico_City");
include("Conexion.php");

// Verificar sesión
if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$id_vet = $_SESSION['veterinario_id'];


/* ELIMINAR TODAS LAS CITAS DE HOY */
if (isset($_GET['borrar_hoy'])) {

    $hoy = date("Y-m-d");

    $sql_delete_hoy = "DELETE FROM citas 
                       WHERE id_veterinaria = ? AND fecha = ?";
    
    $stmt_hoy = $conn->prepare($sql_delete_hoy);
    $stmt_hoy->bind_param("is", $id_vet, $hoy);

    /* VERIFICAR SI HAY CITAS HOY */
    $sql_check_hoy = "SELECT COUNT(*) as total 
                    FROM citas 
                    WHERE id_veterinaria = ? AND fecha = ?";
    $stmt_check = $conn->prepare($sql_check_hoy);
    $stmt_check->bind_param("is", $id_vet, $hoy);
    $stmt_check->execute();
    $res = $stmt_check->get_result()->fetch_assoc();

    if ($res['total'] == 0) {

        // 🔍 VERIFICAR CITAS ANTERIORES (NO FUTURAS)
        $sql_pasadas = "SELECT COUNT(*) as total 
                        FROM citas 
                        WHERE id_veterinaria = ? AND fecha < ?";
        
        $stmt_pasadas = $conn->prepare($sql_pasadas);
        $stmt_pasadas->bind_param("is", $id_vet, $hoy);
        $stmt_pasadas->execute();
        $res_pasadas = $stmt_pasadas->get_result()->fetch_assoc();

        if ($res_pasadas['total'] > 0) {

            echo "<script>
            if(confirm('ℹ No hay citas hoy. ¿Quieres eliminar las citas pasadas?')) {
                window.location='CitasAdmin.php?borrar_pasadas=1';
            } else {
                window.location='CitasAdmin.php';
            }
            </script>";
            exit();

        } else {

            echo "<script>alert('ℹ No hay citas agendadas para hoy ni anteriores'); window.location='CitasAdmin.php';</script>";
            exit();
        }
    }
}

/* ELIMINAR CITAS PASADAS */
if (isset($_GET['borrar_pasadas'])) {

    $hoy = date("Y-m-d");

    $sql_delete_pasadas = "DELETE FROM citas 
                           WHERE id_veterinaria = ? AND fecha < ?";
    
    $stmt_pasadas = $conn->prepare($sql_delete_pasadas);
    $stmt_pasadas->bind_param("is", $id_vet, $hoy);

    if ($stmt_pasadas->execute()) {
        echo "<script>alert('✅ Citas pasadas eliminadas correctamente'); window.location='CitasAdmin.php';</script>";
        exit();
    } else {
        echo "<script>alert('❌ Error al eliminar citas pasadas');</script>";
    }
}

// ELIMINAR CITA
if (isset($_GET['eliminar'])) {
    $id_cita = intval($_GET['eliminar']);

    $sql_delete = "DELETE FROM citas WHERE id = ? AND id_veterinaria = ?";
    $stmt_del = $conn->prepare($sql_delete);
    $stmt_del->bind_param("ii", $id_cita, $id_vet);
    $stmt_del->execute();
}

// OBTENER CITAS DEL VETERINARIO
$sql = "SELECT * FROM citas WHERE id_veterinaria = ? ORDER BY fecha DESC, hora DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_vet);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="CSS/CitasAdmin.css">
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
<link rel="icon" href="img/Pet_Point.png"> 
<title>Citas administración</title>
</head>

<body>

<div class="contenido">
<h1>Citas</h1>

<div class="tabla">
<table class="table">

<tr>
    <th class="filas">Nombre de la mascota</th>
    <th class="filas">Servicio</th>
    <th class="filas">Fecha</th>
    <th class="filas">Hora</th>
    <th class="filas">Contacto</th>
    <th class="ult-fila">Editar</th>
</tr>

<?php if ($result->num_rows > 0): ?>

    <?php while($row = $result->fetch_assoc()): ?>
    <tr>
        <td class="fila"><?php echo htmlspecialchars($row['nombre_mascota']); ?></td>
        <td class="fila"><?php echo htmlspecialchars($row['servicio']); ?></td>
        <td class="fila"><?php echo date("d/m/Y", strtotime($row['fecha'])); ?></td>
        <td class="fila"><?php echo date("H:i", strtotime($row['hora'])); ?></td>
        <td class="fila"><?php echo htmlspecialchars($row['telefono']); ?></td>

        <td class="utl-fila_1">
            <!-- EDITAR  -->
            <a href="ModifCitas.php?id=<?php echo $row['id']; ?>" class="btn-edit">
                <img width="40px" height="40px" src="img/Cita_edit.png">
            </a>

            <!-- ELIMINAR -->
            <a href="CitasAdmin.php?eliminar=<?php echo $row['id']; ?>" 
               class="btn-edit"
               onclick="return confirm('¿Eliminar esta cita?')">
                <img width="40px" height="40px" src="img/Cita_Borrar.png">
            </a>
        </td>
    </tr>
    <?php endwhile; ?>

<?php else: ?>

<tr>
    <td colspan="6" class="fila" style="text-align:center;">
        No hay citas registradas
    </td>
</tr>

<?php endif; ?>

</table>
</div>
</div>

<div class="btn-bor">
     <a href="CitasAdmin.php?borrar_hoy=1" 
   class="btn-borrar"
   onclick="return confirm('¿Estás seguro de eliminar TODAS las citas de hoy?')">
        Borrar citas de hoy
    <img src="img/Img_BorrarCitas.png">
    </a>
</div>


<div class="btn">
    <a href="ServiciosAdmin.html" class="btn-servicio">
    <img src="img/Registro_Regreso.png">
    </a>
</div>


</body>
</html>

<?php $conn->close(); ?>