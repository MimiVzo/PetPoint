<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include("Conexion.php");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agendar Cita</title>
    <link rel="stylesheet" href="CSS/AgregarCita.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
</head>

<body>

<div class="contenido">

    <!-- IZQUIERDA -->
    <div class="content-1">
        <h1>Establecimientos veterinarios</h1>

        <div class="dat-1" id="lista-establecimientos">
            <?php
            $sql = "SELECT id, establecimiento, imagen FROM veterinarios ORDER BY establecimiento";
            $result = $conn->query($sql);

            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    // foto veterinaria
                    $foto = "img/Perfil_Usuario.png";
                    if (!empty($row['imagen'])) {
                            $ruta = __DIR__ . "/" . $row['imagen'];

                        if (file_exists($ruta)) {
                            $foto = $row['imagen'];
                        }
                    }
                    echo '<div class="item-establecimiento" onclick="cargarDetalle('.$row['id'].', \''.htmlspecialchars($row['establecimiento']).'\')">';
                    echo '<img src="'.$foto.'" class="img-mini">';
                    echo '<span>'.htmlspecialchars($row['establecimiento']).'</span>';
                    echo '</div>';
                }
            
            } else {
                echo "<p>No hay establecimientos registrados.</p>";
            }
            ?>
        </div>
    </div>

    <!-- DERECHA -->
    <div class="content-2">
        <h1 id="nombre-establecimiento">Selecciona un establecimiento</h1>

        <div class="dat-2" id="detalle-establecimiento">
            <p>Haz clic en un establecimiento para ver sus detalles.</p>
        </div>
    </div>
</div>



<script>
function cargarDetalle(id, nombre) {
    fetch('detalle_establecimiento.php?id=' + id)
    .then(res => res.text())
    .then(data => {
        document.getElementById('detalle-establecimiento').innerHTML = data;
        document.getElementById('nombre-establecimiento').innerText = nombre;

        // SCROLL automático en celular
        if (window.innerWidth < 768) {
            document.querySelector('.content-2').scrollIntoView({
                behavior: 'smooth'
            });
        }
    });
}
</script>

    <a href="servicios.html" class="btn-servicio">
    <img src="img/Registro_Regreso.png">
    </a>

</body>
</html>