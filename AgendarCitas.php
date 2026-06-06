<?php 
session_start();
include("Conexion.php");

$nombre_vet = "Veterinaria";

/* VALIDAR SESIÓN */
if (!isset($_SESSION['usuario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}

$id_usuario = $_SESSION['usuario_id']; 

/* OBTENER VETERINARIA */
if (isset($_GET['id'])) {
    $id_vet = intval($_GET['id']);

    $sql = "SELECT establecimiento FROM veterinarios WHERE id = $id_vet";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $nombre_vet = $row['establecimiento'];
    }
}

/* CONFIGURACIÓN DEL VETERINARIO */
$sql_conf = "SELECT dias_trabajo, hora_inicio, hora_fin, citas_por_dia, citas_por_hora 
             FROM veterinarios WHERE id = ?";
$stmt_conf = $conn->prepare($sql_conf);
$stmt_conf->bind_param("i", $id_vet);
$stmt_conf->execute();
$config = $stmt_conf->get_result()->fetch_assoc();

$dias_texto = explode(",", $config['dias_trabajo'] ?? "");

$mapa_dias = [
    "Domingo" => 0,
    "Lunes" => 1,
    "Martes" => 2,
    "Miércoles" => 3,
    "Jueves" => 4,
    "Viernes" => 5,
    "Sábado" => 6
];

$dias_trabajo = [];

foreach ($dias_texto as $dia) {
    $dia = trim($dia);
    if (isset($mapa_dias[$dia])) {
        $dias_trabajo[] = $mapa_dias[$dia];
    }
}
$hora_inicio = !empty($config['hora_inicio']) ? $config['hora_inicio'] : "08:00:00";
$hora_fin = !empty($config['hora_fin']) ? $config['hora_fin'] : "19:00:00";

$inicio_hora = (int) date("H", strtotime($hora_inicio));
$fin_hora = (int) date("H", strtotime($hora_fin));
$citas_dia = $config['citas_por_dia'] ?? 30;
$citas_hora = $config['citas_por_hora'] ?? 10;

/* DIAS LLENOS */
$dias_llenos = [];

$sql_dias = "SELECT fecha, COUNT(*) as total 
             FROM citas 
             WHERE id_veterinaria = ?
             GROUP BY fecha";

$stmt_dias = $conn->prepare($sql_dias);
$stmt_dias->bind_param("i", $id_vet);
$stmt_dias->execute();
$result_dias = $stmt_dias->get_result();

while ($row = $result_dias->fetch_assoc()) {
    if ($row['total'] >= $citas_dia) {
        $dias_llenos[] = $row['fecha'];
    }
}

/* HORAS OCUPADAS */
$horas_ocupadas = [];

$sql_horas = "SELECT fecha, hora FROM citas WHERE id_veterinaria = ?";
$stmt_horas = $conn->prepare($sql_horas);
$stmt_horas->bind_param("i", $id_vet);
$stmt_horas->execute();
$result_horas = $stmt_horas->get_result();

while ($row = $result_horas->fetch_assoc()) {
    $horas_ocupadas[] = [
        'fecha' => $row['fecha'],
        'hora' => $row['hora']
    ];
}

/* REGISTRAR CITA */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $nombre_dueno = $_POST['Nombre'];
    $telefono = $_POST['Telefono'];
    $nombre_mascota = $_POST['Nombre_mascota'];
    $especie = $_POST['Especie'];
    $servicio = $_POST['opciones'];
    $fecha = $_POST['fecha'];
    $hora = $_POST['hora'];
    $id_veterinaria = $_POST['id_veterinaria'];

    /* VALIDAR DIA LABORAL */
    $diaSemana = date("w", strtotime($fecha));
    if (!in_array($diaSemana, $dias_trabajo)) {
        $mensaje = "❌ Este día no está disponible";
    } else {

        /* VALIDAR LIMITE POR DIA */
        $sql_check = "SELECT COUNT(*) as total 
                      FROM citas 
                      WHERE id_veterinaria = ? AND fecha = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("is", $id_veterinaria, $fecha);
        $stmt_check->execute();
        $res = $stmt_check->get_result()->fetch_assoc();

        if ($res['total'] >= $citas_dia) {
            $mensaje = "❌ Este día ya está lleno";
        } else {

            /* VALIDAR LIMITE POR HORA */
            $sql_hora = "SELECT COUNT(*) as total 
                         FROM citas 
                         WHERE id_veterinaria = ? AND fecha = ? AND hora = ?";
            $stmt_hora = $conn->prepare($sql_hora);
            $stmt_hora->bind_param("iss", $id_veterinaria, $fecha, $hora);
            $stmt_hora->execute();
            $res_hora = $stmt_hora->get_result()->fetch_assoc();

            if ($res_hora['total'] >= $citas_hora) {
                $mensaje = "❌ Esa hora ya está llena";
            } else {

                $sql = "INSERT INTO citas 
                (id_veterinaria, id_usuario, nombre_dueno, telefono, nombre_mascota, especie, servicio, fecha, hora)
                VALUES 
                ('$id_veterinaria', '$id_usuario', '$nombre_dueno', '$telefono', '$nombre_mascota', '$especie', '$servicio', '$fecha', '$hora')";

       if ($conn->query($sql) === TRUE) {

            // Obtener ID de la cita recién creada
            $id_cita = $conn->insert_id;

            // Guardar en historial
            $sql_historial = "INSERT INTO historial_citas 
            (id_cita, id_veterinaria, id_usuario, nombre_dueno, telefono, nombre_mascota, especie, servicio, fecha, hora)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt_hist = $conn->prepare($sql_historial);
            $stmt_hist->bind_param(
                "iiisssssss",
                $id_cita,
                $id_veterinaria,
                $id_usuario,
                $nombre_dueno,
                $telefono,
                $nombre_mascota,
                $especie,
                $servicio,
                $fecha,
                $hora
            );

            $stmt_hist->execute();

            $mensaje = "✅ ¡Cita registrada correctamente!";
        }else{
                    $mensaje = "❌ Error: " . $conn->error;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="CSS/agendarCitas.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>


<title>Agendar Cita</title>
</head>

<body>

<div class="contenido">

    <div class="content-1">
        <h1>Agendar</h1>

        <div class="dat-1">
            <h2><?= htmlspecialchars($nombre_vet) ?></h2>

            <form method="POST" class="cont-agendar">

            <input class="datos" type="text" name="Nombre" required placeholder="Nombre del dueño">
            <input class="datos" type="text" name="Telefono" required placeholder="Teléfono">

            <input class="datos-mascota1" type="text" name="Nombre_mascota" required placeholder="Nombre de la mascota">
               <select class="datos-mascota2" name="Especie" required>
                <option >Perro</option>
                <option >Gato</option>
                <option >Conejo</option>
                <option >Ave</option>
                <option >Reptil</option>
                <option >Otro</option>
            </select>

            <select name="opciones" required class="datos">
            <option>Chequeo general</option>
            <option>Vacunación</option>
            <option>Desparasitación</option>
            <option>Consulta</option>
            <option>Cirugía</option>
            <option>Urgencia</option>
            <option>Emergencia</option>
            <option>Grooming / Estética</option>
            <option>Pet sitting</option>
            <option>Otro</option>
            </select>

            <input class="date" type="text" id="fecha" name="fecha" required placeholder="Selecciona fechas">

            <select class="time" name="hora" id="hora" required>
            <option value="">Selecciona una hora</option>
            </select>

            <input type="hidden" name="id_veterinaria" value="<?php echo $_GET['id'] ?? ''; ?>">

            <button class="btn-agregar" type="submit">Agendar cita</button>

            </form>
        </div>
    </div>

    <div class="content-2">
        <div class="dat-2">

        <?php if (!empty($mensaje)) { ?>
        <div class="mensaje-box">
        <h2>¡Cita registrada!</h2>
        <p><?= $mensaje ?></p>
        <a href="servicios.html" class="btn-servicio">
        <img src="img/Registro_Regreso.png">
        <span>Regresar a servicios</span>
        </a>
        </div>
        <?php } else { ?>
        <div class="mensaje-box">
        <h2>Agendar cita</h2>
        <p>Completa el formulario para registrar tu cita.</p>
        </div>
        <?php } ?>

        </div>
    </div>

</div>

<script>

let diasLlenos = <?php echo json_encode($dias_llenos); ?>;
let horasOcupadas = <?php echo json_encode($horas_ocupadas); ?>;
let diasTrabajo = <?php echo json_encode($dias_trabajo); ?>;

let inicio = <?php echo $inicio_hora; ?>;
let fin = <?php echo $fin_hora; ?>;
let limiteHora = <?php echo $citas_hora; ?>;

let hoy = new Date().toISOString().split('T')[0];

function generarHoras(fechaSeleccionada) {

    let select = document.getElementById("hora");
    select.innerHTML = '<option value="">Selecciona una hora</option>';

    let ahora = new Date();
    let fechaSel = new Date(fechaSeleccionada + "T00:00:00");
    let hoyDate = new Date();
    hoyDate.setHours(0,0,0,0);

    for (let h = inicio; h < fin; h++) {

        let hora = (h < 10 ? "0" : "") + h + ":00:00";

        let hora12 = h % 12 || 12;
        let ampm = h < 12 ? "AM" : "PM";
        let horaMostrar = hora12 + ":00 " + ampm;

        let totalHora = horasOcupadas.filter(item => 
            item.fecha === fechaSeleccionada && item.hora === hora
        ).length;

        let option = document.createElement("option");
        option.value = hora;

        if (fechaSel.getTime() === hoyDate.getTime() && h <= ahora.getHours()) {
            option.text = horaMostrar + " ⛔";
            option.disabled = true;
        } 
        else if (totalHora >= limiteHora) {
            option.text = horaMostrar + " 🔴 Lleno";
            option.disabled = true;
        } else {
            option.text = horaMostrar + " 🟢 (" + totalHora + "/" + limiteHora + ")";
        }

        select.appendChild(option);
    }
}

/* CALENDARIO */
flatpickr("#fecha", {
    dateFormat: "Y-m-d",

    disable: [
        function(date) {
            let d = date.toISOString().split('T')[0];
            let diaSemana = date.getDay();
            return d < hoy || diasLlenos.includes(d) || !diasTrabajo.includes(diaSemana) ;
        }
    ],


     onChange: function(selectedDates, dateStr) {
        generarHoras(dateStr);
    },

    onDayCreate: function(dObj, dStr, fp, dayElem) {

    let date = dayElem.dateObj.toISOString().split('T')[0];
    let diaSemana = dayElem.dateObj.getDay();

        // PASADO
        if (date < hoy) {
            dayElem.style.background = "#6c757d";
            dayElem.style.color = "#fff";
        }

        // DÍA NO LABORAL
        else if (!diasTrabajo.includes(diaSemana)) {
            dayElem.style.background = "#ff4d4d";
            dayElem.style.color = "#fff";
            dayElem.title = "No trabaja este día";
        }

        // DÍA LLENO
        else if (diasLlenos.includes(date)) {
            dayElem.style.background = "#ff4d4d";
            dayElem.style.color = "#fff";
            dayElem.title = "No disponible";
        }

        // DISPONIBLE
        else {
            dayElem.style.background = "#4CAF50";
            dayElem.style.color = "#fff";
        }
    }
});

</script>

<a href="AgregarCita.php" class="btn-regreso">
<img src="img/Regresar.png">
</a>

</body>
</html>