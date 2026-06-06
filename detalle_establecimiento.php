<?php
include("Conexion.php");

if(isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $sql = "SELECT * FROM veterinarios WHERE id=$id";
    $result = $conn->query($sql);

    if($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // FOTO PERFIL
        $foto = "img/Perfil_Usuario.png";
        if (!empty($row['imagen'])) {
            $ruta = __DIR__ . "/" . $row['imagen'];
            if (file_exists($ruta)) {
                $foto = $row['imagen'];
            }
        }
      echo '
        <div class="card-detalle">

            <div class="img-centro">
                <img src="'.$foto.'" class="img-perfil-detalle">
            </div>

            <p><strong>Horario 🕒</strong><br>' . htmlspecialchars($row['horario']) . '</p>
            <p><strong>Teléfono 📞</strong><br>' . htmlspecialchars($row['telefono']) . '</p>
            <p><strong>Domicilio 📍</strong><br>' . htmlspecialchars($row['calle']) . ' #' . htmlspecialchars($row['numero_exterior']) . ', ' . htmlspecialchars($row['ciudad']) . ', ' . htmlspecialchars($row['estado']) . '.</p>
            <p><strong>Descripción 📖</strong><br>' . htmlspecialchars($row['descripcion']) . '</p>

            <button class="btn-agregar">
                <a href="AgendarCitas.php?id=' . $row['id'] . '">Agendar cita</a>
            </button>

        </div>';
    } else {
        echo "<p>No se encontró la información.</p>";
    }
}
$conn->close();
?>