<?php
session_start();
include("Conexion.php");

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$usuario_id = $_SESSION['usuario_id'];

// Consulta adaptada base de datos
$sql = "SELECT 
            c.nombre_mascota,
            c.servicio,
            c.fecha,
            c.hora,
            v.establecimiento
        FROM citas c
        INNER JOIN veterinarios v ON c.id_veterinaria = v.id
        WHERE c.id_usuario = ?
        ORDER BY c.fecha DESC, c.hora DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="CSS/Citas.css">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png"> 
    <title>Citas</title>
</head>

<body>
    <div class="contenido">
        <h1>Citas</h1>

        <div class="tabla">
            <table class="table">

                <tr>
                    <th class="filas">Establecimiento</th>
                    <th class="filas">Nombre de la mascota</th>
                    <th class="filas">Servicio</th>
                    <th class="filas">Fecha</th>
                    <th class="ult-fila">Hora</th>
                </tr>

                <?php if ($result->num_rows > 0): ?>

                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td class="fila"><?php echo htmlspecialchars($row['establecimiento']); ?></td>
                        <td class="fila"><?php echo htmlspecialchars($row['nombre_mascota']); ?></td>
                        <td class="fila"><?php echo htmlspecialchars($row['servicio']); ?></td>
                        <td class="fila"><?php echo date("d/m/Y", strtotime($row['fecha'])); ?></td>
                        <td class="utl-fila_1"><?php echo date("H:i", strtotime($row['hora'])); ?></td>
                    </tr>
                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td class="fila" colspan="5" style="text-align:center;">
                            No tienes citas registradas
                        </td>
                    </tr>

                <?php endif; ?>

            </table>
        </div>
    </div>

    <a href="servicios.html" class="btn-servicio">
        <img src="img/Registro_Regreso.png">
    </a>
</body>
</html>

<?php
$conn->close();
?>