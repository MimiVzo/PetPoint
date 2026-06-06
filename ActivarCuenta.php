<?php
include("conexion.php");

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'usuario';

    // Seleccionar la tabla según el tipo
    $tabla = ($tipo === 'veterinario') ? 'veterinarios' : 'usuarios';

    $sql = "SELECT * FROM $tabla WHERE activation_code = '$code' AND is_active = 0";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $update = "UPDATE $tabla SET is_active = 1, activation_code = NULL WHERE activation_code = '$code'";
        if ($conn->query($update) === TRUE) {
            header("Location: VerificaCuenta.html");
            exit();
        } else {
            echo "❌ Error al activar la cuenta.";
        }
    } else {
        echo "⚠️ Código inválido o cuenta ya activada.";
    }

    $conn->close();
} else {
    echo "❌ Código no proporcionado.";
}
?>
