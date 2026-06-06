<?php
// ─────────────────────────────────────────────
//  CONEXIÓN
// ─────────────────────────────────────────────
require_once '../Conexion.php';   // expone $conn (mysqli)

// ─────────────────────────────────────────────
//  SESIÓN
// ─────────────────────────────────────────────
session_start();

if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}
$id_veterinaria = (int)$_SESSION['veterinario_id'];

// ─────────────────────────────────────────────
//  ZONA HORARIA
// ─────────────────────────────────────────────
date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

// ─────────────────────────────────────────────
//  MANEJO DE PETICIONES AJAX
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Evitar que warnings de PHP rompan el JSON
    error_reporting(0);
    ini_set('display_errors', '0');
    // Hacer que mysqli lance excepciones en lugar de warnings silenciosos
    mysqli_report(MYSQLI_REPORT_OFF);
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── GUARDAR EVALUACIÓN ──────────────────
    if ($action === 'guardar') {
      try {

        // ── Leer y limpiar campos de texto ───
        $mascota   = trim($_POST['nombre_mascota']          ?? '');
        $dueno     = trim($_POST['nombre_dueno']            ?? '');
        $telefono  = trim($_POST['telefono']                ?? '');
        $especie   = trim($_POST['especie']                 ?? '');
        $raza      = trim($_POST['raza']                    ?? '');
        $edad      = trim($_POST['edad']                    ?? '');
        $sexo      = trim($_POST['sexo']                    ?? '');
        $servicio  = trim($_POST['servicio']                ?? '');
        $vet       = trim($_POST['veterinario']             ?? '');
        $estado    = trim($_POST['estado']                  ?? 'Estable');
        $mucosas   = trim($_POST['mucosas']                 ?? '');
        $est_gen   = trim($_POST['estado_general']          ?? '');
        $anomalias = trim($_POST['anomalias']               ?? '');
        $obs       = trim($_POST['observaciones']           ?? '');
        $indicac   = trim($_POST['indicaciones']            ?? '');

        // ── Fechas / horas (null si vacío) ───
        $fecha    = ($_POST['fecha']        ?? '') !== '' ? $_POST['fecha']        : null;
        $hora     = ($_POST['hora']         ?? '') !== '' ? $_POST['hora']         : null;
        $hora_sal = ($_POST['hora_salida']  ?? '') !== '' ? $_POST['hora_salida']  : null;

        // ── Numéricos (null si vacío) ────────
        $peso  = ($_POST['peso']                    ?? '') !== '' ? (float)$_POST['peso']                    : null;
        $temp  = ($_POST['temperatura']             ?? '') !== '' ? (float)$_POST['temperatura']             : null;
        $fc    = ($_POST['frecuencia_cardiaca']     ?? '') !== '' ? (int)$_POST['frecuencia_cardiaca']       : null;
        $fr    = ($_POST['frecuencia_respiratoria'] ?? '') !== '' ? (int)$_POST['frecuencia_respiratoria']   : null;
        $tlc   = ($_POST['tlc']                     ?? '') !== '' ? (float)$_POST['tlc']                     : null;

        // ── id_cita opcional ─────────────────
        $id_cita = (isset($_POST['id_cita']) && $_POST['id_cita'] !== '') ? (int)$_POST['id_cita'] : null;

        // ────────────────────────────────────────────────────────────
        //  INSERT en evaluaciones
        //  Usamos escape manual para strings y NULL literal para
        //  numéricos vacíos — mysqli bind_param no acepta null en
        //  tipos d/i y lanza fatal error que rompe el JSON.
        // ────────────────────────────────────────────────────────────

        // Función helper: escapa string o devuelve NULL SQL
        $e = function($v) use ($conn) {
            return ($v !== null && $v !== '') ? "'" . $conn->real_escape_string($v) . "'" : 'NULL';
        };
        // Numérico: devuelve el valor o NULL
        $n  = fn($v) => $v !== null ? (float)$v : 'NULL';
        $ni = fn($v) => $v !== null ? (int)$v   : 'NULL';

        $sql = "INSERT INTO evaluaciones
                  (id_veterinaria,
                   nombre_mascota, nombre_dueno, telefono,
                   especie, raza, edad, sexo, servicio, veterinario,
                   fecha, hora, hora_salida, estado,
                   peso, temperatura, frecuencia_cardiaca, frecuencia_respiratoria,
                   tlc, mucosas, estado_general, anomalias, observaciones, indicaciones)
                VALUES (
                   {$id_veterinaria},
                   {$e($mascota)}, {$e($dueno)}, {$e($telefono)},
                   {$e($especie)}, {$e($raza)}, {$e($edad)}, {$e($sexo)},
                   {$e($servicio)}, {$e($vet)},
                   {$e($fecha)}, {$e($hora)}, {$e($hora_sal)}, {$e($estado)},
                   {$n($peso)}, {$n($temp)}, {$ni($fc)}, {$ni($fr)},
                   {$n($tlc)}, {$e($mucosas)}, {$e($est_gen)},
                   {$e($anomalias)}, {$e($obs)}, {$e($indicac)}
                )";

        if (!$conn->query($sql)) {
            echo json_encode(['ok' => false, 'error' => 'execute: ' . $conn->error]);
            exit;
        }

        $new_id = $conn->insert_id;

        // ── AUTO-SYNC expedientes (no bloquea el ok si falla) ───────
        try {
            $chk = $conn->prepare("
                SELECT id FROM expedientes
                 WHERE CONVERT(nombre_mascota USING utf8mb4) = CONVERT(? USING utf8mb4)
                   AND CONVERT(nombre_dueno   USING utf8mb4) = CONVERT(? USING utf8mb4)
                   AND id_veterinaria = ?
                 LIMIT 1
            ");
            $chk->bind_param('ssi', $mascota, $dueno, $id_veterinaria);
            $chk->execute();
            $expRow = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($expRow) {
                // Usar query directa para evitar el problema de null en bind_param
                $esp_e  = $conn->real_escape_string($especie);
                $tel_e  = $conn->real_escape_string($telefono);
                $raza_e = $conn->real_escape_string($raza);
                $edad_e = $conn->real_escape_string($edad);
                $sexo_e = $conn->real_escape_string($sexo);
                $peso_s = ($peso !== null) ? (float)$peso : 'NULL';
                $conn->query("
                    UPDATE expedientes
                       SET especie  = COALESCE(NULLIF('$esp_e',  ''), especie),
                           telefono = COALESCE(NULLIF('$tel_e',  ''), telefono),
                           raza     = COALESCE(NULLIF('$raza_e', ''), raza),
                           edad     = COALESCE(NULLIF('$edad_e', ''), edad),
                           sexo     = COALESCE(NULLIF('$sexo_e', ''), sexo),
                           peso     = COALESCE($peso_s, peso)
                     WHERE id = {$expRow['id']}
                ");
            } else {
                $mas_e  = $conn->real_escape_string($mascota);
                $due_e  = $conn->real_escape_string($dueno);
                $esp_e  = $conn->real_escape_string($especie);
                $tel_e  = $conn->real_escape_string($telefono);
                $raza_e = $conn->real_escape_string($raza);
                $edad_e = $conn->real_escape_string($edad);
                $sexo_e = $conn->real_escape_string($sexo);
                $peso_s = ($peso !== null) ? (float)$peso : 'NULL';
                $conn->query("
                    INSERT INTO expedientes
                      (id_veterinaria, nombre_mascota, nombre_dueno,
                       especie, telefono, raza, edad, sexo, peso)
                    VALUES ({$id_veterinaria},'$mas_e','$due_e',
                            '$esp_e','$tel_e','$raza_e','$edad_e','$sexo_e',$peso_s)
                ");
            }
        } catch (Exception $ex) { /* sync falló, no bloquear */ }

        // ── AUTO-SYNC historial_citas (solo si viene de una cita) ───
        if ($id_cita) {
          try {
            $chkH = $conn->prepare("SELECT id FROM historial_citas WHERE id_cita = ? LIMIT 1");
            $chkH->bind_param('i', $id_cita);
            $chkH->execute();
            $existeH = $chkH->get_result()->fetch_assoc();
            $chkH->close();

            if ($existeH) {
                $obs_e     = $conn->real_escape_string($obs);
                $estado_e  = $conn->real_escape_string($estado);
                $horsal_e  = $hora_sal ? "'".$conn->real_escape_string($hora_sal)."'" : 'NULL';
                $conn->query("
                    UPDATE historial_citas
                       SET estado_ui = '$estado_e', notas_clinicas = '$obs_e',
                           hora_salida = $horsal_e
                     WHERE id_cita = $id_cita AND id_veterinaria = $id_veterinaria
                ");
            } else {
                $sc = $conn->prepare("SELECT * FROM citas WHERE id = ? AND id_veterinaria = ?");
                $sc->bind_param('ii', $id_cita, $id_veterinaria);
                $sc->execute();
                $cita = $sc->get_result()->fetch_assoc();
                $sc->close();

                if ($cita) {
                    $sv = $conn->prepare("SELECT establecimiento FROM veterinarios WHERE id = ?");
                    $sv->bind_param('i', $id_veterinaria);
                    $sv->execute();
                    $vetRow         = $sv->get_result()->fetch_assoc();
                    $sv->close();
                    $nombreVetEstab = $conn->real_escape_string($vetRow['establecimiento'] ?? '');
                    $idusr          = (int)($cita['id_usuario'] ?? 0);
                    $due_e   = $conn->real_escape_string($cita['nombre_dueno']);
                    $mas_e   = $conn->real_escape_string($cita['nombre_mascota']);
                    $svc_e   = $conn->real_escape_string($cita['servicio']);
                    $fec_e   = $conn->real_escape_string($cita['fecha']);
                    $hor_e   = $conn->real_escape_string($cita['hora']);
                    $tel_e   = $conn->real_escape_string($cita['telefono']);
                    $esp_e   = $conn->real_escape_string($cita['especie']);
                    $obs_e   = $conn->real_escape_string($obs);
                    $est_e   = $conn->real_escape_string($estado);
                    $horsal_e= $hora_sal ? "'".$conn->real_escape_string($hora_sal)."'" : 'NULL';
                    $conn->query("
                        INSERT INTO historial_citas
                          (id_cita, id_veterinaria, id_usuario,
                           nombre_dueno, nombre_mascota, servicio,
                           fecha, hora, hora_salida,
                           estado, estado_ui,
                           telefono, especie, notas_clinicas, nombre_veterinaria)
                        VALUES ($id_cita, $id_veterinaria, $idusr,
                                '$due_e','$mas_e','$svc_e',
                                '$fec_e','$hor_e',$horsal_e,
                                'completada','$est_e',
                                '$tel_e','$esp_e','$obs_e','$nombreVetEstab')
                    ");
                }
            }
          } catch (Exception $ex) { /* sync falló, no bloquear */ }
        }

        echo json_encode(['ok' => true, 'id' => $new_id]);
        exit;
      } catch (Exception $ex) {
          echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
          exit;
      }
    } // fin guardar

    // ── ELIMINAR EVALUACIÓN ─────────────────
    if ($action === 'eliminar') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM evaluaciones WHERE id = ? AND id_veterinaria = ?");
        $stmt->bind_param('ii', $id, $id_veterinaria);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => (bool)$ok]);
        exit;
    }

    // ── VER UNA EVALUACIÓN ──────────────────
    if ($action === 'ver') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM evaluaciones WHERE id = ? AND id_veterinaria = ?");
        $stmt->bind_param('ii', $id, $id_veterinaria);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode($row ?: ['error' => 'No encontrada']);
        exit;
    }

    // ── CITAS PENDIENTES ────────────────────
    if ($action === 'citas_pendientes') {
        $stmt = $conn->prepare(
            "SELECT c.id, c.nombre_mascota, c.nombre_dueno, c.telefono,
                    c.especie, c.servicio, c.fecha, c.hora
             FROM citas c
             WHERE c.id_veterinaria = ?
               AND c.fecha >= CURDATE()
             ORDER BY c.fecha ASC, c.hora ASC"
        );
        $stmt->bind_param('i', $id_veterinaria);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode($rows);
        exit;
    }

    // ── ACTUALIZAR ESTADO ───────────────────
    if ($action === 'actualizar_estado') {
        $id     = (int)($_POST['id']    ?? 0);
        $estado = trim($_POST['estado'] ?? '');
        $stmt   = $conn->prepare("UPDATE evaluaciones SET estado = ? WHERE id = ? AND id_veterinaria = ?");
        $stmt->bind_param('sii', $estado, $id, $id_veterinaria);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => (bool)$ok]);
        exit;
    }
}

// ─────────────────────────────────────────────
//  CARGA INICIAL
// ─────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT * FROM evaluaciones
     WHERE id_veterinaria = ?
     ORDER BY fecha DESC, hora DESC"
);
$stmt->bind_param('i', $id_veterinaria);
$stmt->execute();
$evaluaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT c.id, c.nombre_mascota, c.nombre_dueno, c.telefono,
            c.especie, c.servicio, c.fecha, c.hora
     FROM citas c
     WHERE c.id_veterinaria = ?
       AND c.fecha >= CURDATE()
     ORDER BY c.fecha ASC, c.hora ASC"
);
$stmt->bind_param('i', $id_veterinaria);
$stmt->execute();
$citas_disponibles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Helpers PHP ──────────────────────────────
function initials(string $name): string {
    $words = preg_split('/\s+/', trim($name));
    $ini   = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $ini ?: '??';
}
function badgeClass(string $especie): string {
    $map = ['gato'=>'bp','perro'=>'bb','conejo'=>'bg','ave'=>'bo','reptil'=>'br'];
    return $map[strtolower($especie)] ?? 'bgray';
}
function estadoColor(string $estado): string {
    $map = [
        'estable'        => 'status-verde',
        'en observación' => 'status-amarillo',
        'urgente'        => 'status-rojo',
        'alta'           => 'status-verde',
        'esperando'      => 'status-azul',
        'no asistió'     => 'status-gris',
    ];
    return $map[strtolower($estado)] ?? 'status-verde';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/Pet_Point.png" type="imagen/png">
    <link rel="stylesheet" href="Evaluacion_ingreso.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
<title>Evaluación de Ingreso</title>
</head>
<body>
<div class="app">

<!-- ============ LISTA ============ -->
<div class="screen active" id="s-list">
  <div class="topbar">
    <h2>Evaluación de ingreso</h2>
    <div style="flex:1"></div>
    <button class="btn-primary" onclick="go('s-nueva')">+ Agregar evaluación</button>
  </div>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
    <input class="search" id="buscador" placeholder="Buscar por nombre de mascota o dueño" oninput="filtrarEvals()">
    <select class="li-estado" id="filter-estado" onchange="filtrarEvals()">
      <option value="">Todos los estados</option>
      <option value="Estable">Estable</option>
      <option value="En observación">En observación</option>
      <option value="Urgente">Urgente</option>
      <option value="Alta">Alta</option>
      <option value="Esperando">Esperando</option>
      <option value="No asistió">No asistió</option>
    </select>
  </div>

  <div id="eval-list">
    <?php if (empty($evaluaciones)): ?>
      <div class="empty">No hay evaluaciones registradas aún.</div>
    <?php else:
      $anomColors = [
        'Sano / sin anomalías'=>'chip-verde','Herida abierta'=>'chip-rojo','Cortada'=>'chip-rojo',
        'Moretones / golpes'=>'chip-rojo','Sangrado activo'=>'chip-rojo','Fractura evidente'=>'chip-rojo',
        'Quemadura'=>'chip-rojo','Vómito reciente'=>'chip-amarillo','Diarrea'=>'chip-amarillo',
        'Fiebre'=>'chip-amarillo','Cojera'=>'chip-amarillo','Convulsiones'=>'chip-rojo',
        'Dificultad para respirar'=>'chip-rojo','Pérdida de apetito'=>'chip-amarillo',
        'Decaimiento general'=>'chip-amarillo','Distensión abdominal'=>'chip-rojo',
        'Estornudos / secreción nasal'=>'chip-amarillo','Secreción ocular'=>'chip-amarillo',
        'Parásitos externos visibles'=>'chip-rosa','Alopecia / pérdida de pelo'=>'chip-rosa',
        'Dermatitis / sarpullido'=>'chip-rosa','Pelaje opaco / maltratado'=>'chip-rosa',
        'Uñas excesivamente largas'=>'chip-rosa','Nódulos o masas palpables'=>'chip-rosa',
        'Agresividad inusual'=>'chip-amarillo','Miedo excesivo'=>'chip-amarillo',
        'Desorientación'=>'chip-amarillo','Pérdida de equilibrio'=>'chip-rojo',
        'Nervioso / ansioso'=>'chip-amarillo',
      ];
      foreach ($evaluaciones as $ev):
        $ini    = initials($ev['nombre_mascota'] ?? '');
        $bdgCls = badgeClass($ev['especie'] ?? '');
        $stCls  = estadoColor($ev['estado'] ?? '');
        $anomArr= !empty($ev['anomalias']) ? explode(',', $ev['anomalias']) : [];
    ?>
      <div class="eval-card"
           onclick="verEval(<?= (int)$ev['id'] ?>)"
           data-mascota="<?= htmlspecialchars(strtolower($ev['nombre_mascota'] ?? '')) ?>"
           data-dueno="<?= htmlspecialchars(strtolower($ev['nombre_dueno'] ?? '')) ?>"
           data-estado="<?= htmlspecialchars($ev['estado'] ?? '') ?>">
        <div class="eval-header">
          <div class="eval-avatar"><?= htmlspecialchars($ini) ?></div>
          <div class="eval-info">
            <div class="eval-name">
              <?= htmlspecialchars($ev['nombre_mascota'] ?? '—') ?>
              <span class="badge <?= $bdgCls ?>" style="font-size:10px"><?= htmlspecialchars($ev['especie'] ?? '') ?></span>
            </div>
            <div class="eval-meta">
              Dueño: <?= htmlspecialchars($ev['nombre_dueno'] ?? '—') ?> · <?= htmlspecialchars($ev['servicio'] ?? '—') ?>
            </div>
            <div style="margin-top:5px">
              <?php foreach (array_slice($anomArr, 0, 2) as $a):
                $a  = trim($a);
                $cc = $anomColors[$a] ?? 'chip-gray';
              ?>
                <span class="chip <?= $cc ?>"><?= htmlspecialchars($a) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="eval-right">
            <div class="eval-date"><?= htmlspecialchars($ev['fecha'] ?? '') ?>  |  <?= htmlspecialchars(substr($ev['hora'] ?? '00:00', 0, 5)) ?></div>
            <div class="eval-status <?= $stCls ?>">● <?= htmlspecialchars($ev['estado'] ?? '—') ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="empty" id="empty-eval" style="display:none">No se encontraron evaluaciones.</div>
</div>

<!-- ============ NUEVA EVALUACIÓN ============ -->
<div class="screen" id="s-nueva">
  <div class="topbar">
    <button class="btn-back" onclick="go('s-list')">< Evaluaciones</button>
    <h2>Nueva evaluación de ingreso</h2>
  </div>

  <?php if (!empty($citas_disponibles)): ?>
  <div class="cita-banner">
    <label>Cargar desde cita:</label>
    <select id="sel-cita">
      <option value="">— Seleccionar cita agendada —</option>
      <?php foreach ($citas_disponibles as $c): ?>
        <option value="<?= (int)$c['id'] ?>"
                data-mascota="<?= htmlspecialchars($c['nombre_mascota']) ?>"
                data-dueno="<?= htmlspecialchars($c['nombre_dueno']) ?>"
                data-tel="<?= htmlspecialchars($c['telefono']) ?>"
                data-especie="<?= htmlspecialchars($c['especie']) ?>"
                data-servicio="<?= htmlspecialchars($c['servicio']) ?>"
                data-fecha="<?= htmlspecialchars($c['fecha']) ?>"
                data-hora="<?= htmlspecialchars(substr($c['hora'],0,5)) ?>">
          <?= htmlspecialchars($c['fecha']) ?> <?= htmlspecialchars(substr($c['hora'],0,5)) ?>
          — <?= htmlspecialchars($c['nombre_mascota']) ?>
          (<?= htmlspecialchars($c['nombre_dueno']) ?>)
          · <?= htmlspecialchars($c['servicio']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn-cita-cargar" onclick="cargarDesdeCita()">Autocompletar ↓</button>
  </div>
  <?php endif; ?>

  <input type="hidden" id="n-id-cita" value="">

  <div class="steps">
    <div class="step active" id="step-1">1. Identificación</div>
    <div class="step" id="step-2">2. Signos vitales</div>
    <div class="step" id="step-3">3. Estado y anomalías</div>
    <div class="step" id="step-4">4. Observaciones</div>
  </div>

  <!-- Paso 1 -->
  <div id="paso-1">
    <div class="card">
      <div class="sep">Datos del paciente</div>
      <div class="row2">
        <div class="f"><label class="lbl">Nombre de la mascota </label><input id="n-pet" placeholder="Ej. Mazapán"></div>
        <div class="f"><label class="lbl">Especie </label>
          <select id="n-species">
            <option value="">Seleccionar...</option>
            <option>Gato</option><option>Perro</option><option>Conejo</option><option>Ave</option><option>Reptil</option><option>Otro</option>
          </select>
        </div>
      </div>
      <div class="row3">
        <div class="f"><label class="lbl">Raza</label><input id="n-breed" placeholder="Ej. Criollo"></div>
        <div class="f"><label class="lbl">Edad aproximada</label><input id="n-age" placeholder="Ej. 2 años"></div>
        <div class="f"><label class="lbl">Sexo</label>
          <select id="n-sex"><option value="">Seleccionar...</option><option>Macho</option><option>Hembra</option></select>
        </div>
      </div>
      <div class="row2">
        <div class="f"><label class="lbl">Nombre del dueño </label><input id="n-owner" placeholder="Nombre completo"></div>
        <div class="f"><label class="lbl">Teléfono de contacto </label><input readonly id="n-phone" placeholder="000 000 0000"></div>
      </div>
      <div class="row2">
        <div class="f"><label class="lbl">Servicio agendado </label>
          <select id="n-service">
            <option value="">Seleccionar...</option>
            <option>Chequeo general</option><option>Vacunación</option><option>Desparasitación</option>
            <option>Consulta</option><option>Cirugía</option><option>Urgencia</option>
            <option>Emergencia</option><option>Grooming / Estética</option>
            <option>Pet sitting</option><option>Otro</option>
          </select>
        </div>
        <div class="f"><label class="lbl">Veterinario responsable</label><input id="n-vet" placeholder="Nombre del veterinario"></div>
      </div>
      <div class="row2">
        <div class="f"><label class="lbl">Fecha de ingreso </label><input type="date" id="n-date"></div>
        <div class="f"><label class="lbl">Hora de ingreso </label><input type="time" id="n-time" value="17:00" style="width:100%"></div>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end">
      <button class="btn-primary" onclick="paso(2)">Siguiente →</button>
    </div>
  </div>

  <!-- Paso 2 -->
  <div id="paso-2" style="display:none">
    <div class="card">
      <div class="sep">Signos vitales al ingreso</div>
      <div class="row2">
        <div class="vital-card">
          <div class="vital-row"><span class="vital-label">Peso</span><input class="vital-input" id="v-peso" type="number" step="0.1" placeholder="Ej. 0.0"><span class="vital-unit">kg</span></div>
          <div class="vital-row"><span class="vital-label">Temperatura</span><input class="vital-input" id="v-temp" type="number" step="0.1" placeholder="Ej. 38.5"><span class="vital-unit">°C</span></div>
          <div class="vital-row"><span class="vital-label">Frecuencia cardíaca</span><input class="vital-input" id="v-fc" type="number" placeholder="Ej. 120"><span class="vital-unit">lpm</span></div>
        </div>
        <div class="vital-card">
          <div class="vital-row"><span class="vital-label">Frecuencia respiratoria</span><input class="vital-input" id="v-fr" type="number" placeholder="Ej. 24"><span class="vital-unit">rpm</span></div>
          <div class="vital-row"><span class="vital-label">Tiempo de llenado capilar</span><input class="vital-input" id="v-tlc" type="number" step="0.1" placeholder="Ej. 2"><span class="vital-unit">seg</span></div>
          <div class="vital-row"><span class="vital-label">Color de mucosas</span>
            <select id="v-mucosas" style="font-weight:500;padding:6px 10px;border-radius:8px;border:1.5px solid #a36287;font-size:12px;outline:none;min-width:90px;color:#c4195b;">
              <option>Rosadas</option><option>Pálidas</option><option>Cianóticas</option><option>Ictéricas</option><option>Congestionadas</option>
            </select>
          </div>
        </div>
      </div>
      <div class="f"><label class="lbl">Observaciones sobre signos vitales</label>
        <textarea id="v-obs" placeholder="Ej. Frecuencia cardíaca elevada por estrés..."></textarea>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between">
      <button class="btn-sec" onclick="paso(1)">← Anterior</button>
      <button class="btn-primary" onclick="paso(3)">Siguiente →</button>
    </div>
  </div>

  <!-- Paso 3 -->
  <div id="paso-3" style="display:none">
    <div class="card">
      <div class="sep">Estado general del animal</div>
      <label class="lbl">Selecciona el estado al momento de llegar</label>
      <div class="estado-grid" id="estado-btns">
        <button class="e-btn" onclick="selEstado(this,'e-verde')">Alerta y activo</button>
        <button class="e-btn" onclick="selEstado(this,'e-azul')">Tranquilo</button>
        <button class="e-btn" onclick="selEstado(this,'e-amarillo')">Nervioso / ansioso</button>
        <button class="e-btn" onclick="selEstado(this,'e-amarillo')">Letárgico</button>
        <button class="e-btn" onclick="selEstado(this,'e-rojo')">Desvanecido</button>
        <button class="e-btn" onclick="selEstado(this,'e-rojo')">Inconsciente</button>
        <button class="e-btn" onclick="selEstado(this,'e-amarillo')">Con dolor evidente</button>
        <button class="e-btn" onclick="selEstado(this,'e-rojo')">En shock</button>
      </div>
    </div>

    <div class="card" style="margin-top:0">
      <div class="sep">Anomalías presentes al ingreso</div>
      <label class="lbl" style="margin-bottom:10px">Selecciona todas las que apliquen</label>

      <h3 style="font-size:16px;color:#27500a;margin-bottom:8px">Sin anomalías</h3>
      <div class="anom-grid">
        <button class="a-btn" onclick="toggleAnom(this,'a-on-verde')">Sano / sin anomalías</button>
      </div>
      <h3 style="font-size:16px;color:#633806;margin:10px 0 8px">Traumatismos</h3>
      <div class="anom-grid">
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Herida abierta</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Cortada</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Moretones / golpes</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Sangrado activo</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Fractura evidente</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Quemadura</button>
      </div>
      <h3 style="font-size:16px;color:#633806;margin:10px 0 8px">Síntomas clínicos</h3>
      <div class="anom-grid">
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Vómito reciente</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Diarrea</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Fiebre</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Cojera</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Convulsiones</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Dificultad para respirar</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Pérdida de apetito</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Decaimiento general</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Distensión abdominal</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Estornudos / secreción nasal</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Secreción ocular</button>
      </div>
      <h3 style="font-size:16px;color:#72243e;margin:10px 0 8px">Piel y pelaje</h3>
      <div class="anom-grid">
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rosa')">Parásitos externos visibles</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rosa')">Alopecia / pérdida de pelo</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rosa')">Dermatitis / sarpullido</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rosa')">Pelaje opaco / maltratado</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rosa')">Uñas excesivamente largas</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rosa')">Nódulos o masas palpables</button>
      </div>
      <h3 style="font-size:16px;color:#0c447c;margin:10px 0 8px">Comportamiento</h3>
      <div class="anom-grid">
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Agresividad inusual</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Miedo excesivo</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-amarillo')">Desorientación</button>
        <button class="a-btn" onclick="toggleAnom(this,'a-on-rojo')">Pérdida de equilibrio</button>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between">
      <button class="btn-sec" onclick="paso(2)">← Anterior</button>
      <button class="btn-primary" onclick="paso(4)">Siguiente →</button>
    </div>
  </div>

  <!-- Paso 4 -->
  <div id="paso-4" style="display:none">
    <div class="card">
      <div class="sep">Observaciones clínicas</div>
      <div class="f"><label class="lbl">Descripción detallada del estado de ingreso</label>
        <textarea id="obs-detalle" rows="4" placeholder="Describe con detalle el estado del paciente al momento de ingresar..."></textarea>
      </div>
      <div class="f"><label class="lbl">Indicaciones al dueño</label>
        <textarea id="obs-indicaciones" placeholder="Instrucciones que se le dieron al dueño..."></textarea>
      </div>
      <div class="row2">
        <div class="f"><label class="lbl">Estado general asignado</label>
          <select id="n-estado">
            <option value="Esperando">Esperando</option>
            <option value="Estable">Estable</option>
            <option value="En observación">En observación</option>
            <option value="Urgente">Urgente</option>
            <option value="Alta">Alta</option>
            <option value="No asistió">No asistió</option>
          </select>
        </div>
        <div class="f"><label class="lbl">Hora estimada de salida</label>
          <input type="time" id="n-hora-salida" style="width:100%">
        </div>
      </div>
    </div>

    <div class="card" style="background:#fdf0f7;border-color:#e8cee0">
      <div class="sep">Resumen de la evaluación</div>
      <div id="resumen-contenido" style="font-size:16px;color:#001f44;line-height:1.8">
        <p style="color:#9a7a8a;font-style:italic">Completa los pasos anteriores para ver el resumen.</p>
      </div>
    </div>

    <div id="nueva-ok"  class="ok-bar"  style="display:none"><p>✓ Evaluación registrada exitosamente</p></div>
    <div id="nueva-err" class="err-bar" style="display:none"><p id="nueva-err-msg">Error al guardar.</p></div>

    <div style="display:flex;justify-content:space-between">
      <button class="btn-sec" onclick="paso(3)">← Anterior</button>
      <button class="btn-primary" id="btn-guardar" onclick="guardarEval()">Guardar evaluación</button>
    </div>
  </div>
</div>

<!-- ============ VER EVALUACIÓN ============ -->
<div class="screen" id="s-ver">
  <div class="topbar">
    <button class="btn-back" onclick="go('s-list')">← Evaluaciones</button>
    <h2 id="ver-titulo">Evaluación</h2>
    <div style="flex:1"></div>
    <button class="btn-sm btn-del" onclick="confirmarEliminar()">Eliminar evaluación</button>
  </div>

  <div id="ver-estado-bar" style="border-radius:10px;padding:10px 16px;margin-bottom:16px;font-size:14px;font-weight:700;text-align:center;"></div>

  <div class="card">
    <div class="sep">Datos del paciente</div>
    <div class="row2">
      <div>
        <div class="lbl">Mascota</div><div class="info-val" id="ver-pet">—</div>
        <div style="height:10px"></div>
        <div class="lbl">Especie / Raza</div><div class="info-val" id="ver-species">—</div>
        <div style="height:10px"></div>
        <div class="lbl">Servicio</div><div class="info-val" id="ver-service">—</div>
      </div>
      <div>
        <div class="lbl">Dueño</div><div class="info-val" id="ver-owner">—</div>
        <div style="height:10px"></div>
        <div class="lbl">Teléfono</div><div class="info-val" id="ver-phone">—</div>
        <div style="height:10px"></div>
        <div class="lbl">Fecha y hora de ingreso</div><div class="info-val" id="ver-fecha">—</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="sep">Signos vitales</div>
    <div class="vital-display" id="ver-vitales"></div>
    <div style="margin-top:12px" id="ver-vitales-obs"></div>
  </div>

  <div class="card">
    <div class="sep">Estado general</div>
    <div id="ver-estado-chips" class="chip-list"></div>
  </div>

  <div class="card">
    <div class="sep">Anomalías detectadas</div>
    <div id="ver-anomalias" class="chip-list"></div>
  </div>

  <div class="card">
    <div class="sep">Observaciones clínicas</div>
    <div class="lbl">Descripción del estado de ingreso</div>
    <div class="info-val" id="ver-obs" style="margin-bottom:14px">—</div>
    <div class="lbl">Indicaciones al dueño</div>
    <div class="info-val" id="ver-indicaciones">—</div>
  </div>
</div>

</div><!-- /app -->

<div class="btn">
    <a href="../ServiciosAdmin.html" class="btn-servicio">
    <img alt="Regresar" src="../img/Registro_Regreso.png">
    </a>
</div>

<script>
var PHP_FILE  = 'Evaluacion_ingreso.php';
var curEvalId = null;

function go(id){
  document.querySelectorAll('.screen').forEach(function(s){ s.classList.remove('active'); });
  document.getElementById(id).classList.add('active');
  window.scrollTo({top:0, behavior:'smooth'});
}

function filtrarEvals(){
  var q      = document.getElementById('buscador').value.toLowerCase();
  var estado = document.getElementById('filter-estado').value;
  var cards  = document.querySelectorAll('#eval-list .eval-card');
  var vis    = 0;
  cards.forEach(function(c){
    var mQ = q === '' || c.dataset.mascota.includes(q) || c.dataset.dueno.includes(q);
    var mE = estado === '' || c.dataset.estado === estado;
    c.style.display = (mQ && mE) ? 'block' : 'none';
    if (mQ && mE) vis++;
  });
  document.getElementById('empty-eval').style.display = vis === 0 ? 'block' : 'none';
}

function cargarDesdeCita(){
  var sel = document.getElementById('sel-cita');
  var opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) { alert('Selecciona una cita de la lista primero.'); return; }
  document.getElementById('n-pet').value     = opt.dataset.mascota  || '';
  document.getElementById('n-owner').value   = opt.dataset.dueno    || '';
  document.getElementById('n-phone').value   = opt.dataset.tel      || '';
  document.getElementById('n-date').value    = opt.dataset.fecha    || '';
  document.getElementById('n-time').value    = opt.dataset.hora     || '';
  document.getElementById('n-id-cita').value = opt.value;
  var espSel = document.getElementById('n-species');
  var espVal = (opt.dataset.especie || '').trim();
  for (var i = 0; i < espSel.options.length; i++){
    if (espSel.options[i].text.toLowerCase() === espVal.toLowerCase()){ espSel.selectedIndex = i; break; }
  }
  var svcSel = document.getElementById('n-service');
  var svcVal = (opt.dataset.servicio || '').trim();
  for (var j = 0; j < svcSel.options.length; j++){
    if (svcSel.options[j].text.toLowerCase() === svcVal.toLowerCase()){ svcSel.selectedIndex = j; break; }
  }
  sel.style.border = '1.5px solid #7c3fa0';
  setTimeout(function(){ sel.style.border = ''; }, 1500);
}

function verEval(id){
  curEvalId = id;
  var fd = new FormData();
  fd.append('action', 'ver');
  fd.append('id', id);
  fetch(PHP_FILE, {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(e){
      if (e.error){ alert('No se pudo cargar la evaluación.'); return; }
      document.getElementById('ver-titulo').textContent = 'Evaluación — ' + e.nombre_mascota;
      var barColors = {
        'Estable':'background:#c0dd97;color:#27500a','Urgente':'background:#f7c1c1;color:#791f1f',
        'En observación':'background:#fac775;color:#633806','Alta':'background:#b5d4f4;color:#0c447c',
        'Esperando':'background:#dce8f8;color:#1460a8','No asistió':'background:#e8e8e8;color:#555'
      };
      var bar = document.getElementById('ver-estado-bar');
      bar.style.cssText = barColors[e.estado] || 'background:#e8e8e8;color:#555';
      bar.textContent   = 'Estado: ' + e.estado + ' · ' + e.servicio;
      document.getElementById('ver-pet').textContent     = e.nombre_mascota + ' · ' + (e.sexo||'—') + ' · ' + (e.edad||'—');
      document.getElementById('ver-species').textContent = e.especie + (e.raza ? ' / ' + e.raza : '');
      document.getElementById('ver-service').textContent = e.servicio + ' · Veterinario: ' + (e.veterinario||'—');
      document.getElementById('ver-owner').textContent   = e.nombre_dueno;
      document.getElementById('ver-phone').textContent   = e.telefono;
      document.getElementById('ver-fecha').textContent   =
        e.fecha + ' · ' + (e.hora||'').substring(0,5) +
        (e.hora_salida ? ' — Salida: ' + e.hora_salida.substring(0,5) : '');
      var vitals = [
        {v:(e.peso?e.peso+' kg':'—'),l:'Peso'},
        {v:(e.temperatura?e.temperatura+' °C':'—'),l:'Temperatura'},
        {v:(e.frecuencia_cardiaca?e.frecuencia_cardiaca+' lpm':'—'),l:'Frec. cardíaca'},
        {v:(e.frecuencia_respiratoria?e.frecuencia_respiratoria+' rpm':'—'),l:'Frec. respiratoria'},
        {v:(e.tlc?e.tlc+' seg':'—'),l:'Llenado capilar'},
        {v:e.mucosas||'—',l:'Mucosas'}
      ];
      document.getElementById('ver-vitales').innerHTML = vitals.map(function(x){
        return '<div class="vd-card"><div class="vd-val">'+x.v+'</div><div class="vd-lbl">'+x.l+'</div></div>';
      }).join('');
      document.getElementById('ver-vitales-obs').innerHTML = e.observaciones
        ? '<div class="lbl">Observaciones</div><div class="info-val">'+esc(e.observaciones)+'</div>' : '';
      var estadoArr = (e.estado_general||'').split(',').map(function(s){return s.trim();}).filter(Boolean);
      document.getElementById('ver-estado-chips').innerHTML = estadoArr.length
        ? estadoArr.map(function(s){ return '<span class="chip chip-azul">'+esc(s)+'</span>'; }).join(' ')
        : '<span class="chip chip-gray">No registrado</span>';
      var anomColors = {
        'Sano / sin anomalías':'chip-verde','Herida abierta':'chip-rojo','Cortada':'chip-rojo',
        'Moretones / golpes':'chip-rojo','Sangrado activo':'chip-rojo','Fractura evidente':'chip-rojo',
        'Quemadura':'chip-rojo','Vómito reciente':'chip-amarillo','Diarrea':'chip-amarillo',
        'Fiebre':'chip-amarillo','Cojera':'chip-amarillo','Convulsiones':'chip-rojo',
        'Dificultad para respirar':'chip-rojo','Pérdida de apetito':'chip-amarillo',
        'Decaimiento general':'chip-amarillo','Distensión abdominal':'chip-rojo',
        'Estornudos / secreción nasal':'chip-amarillo','Secreción ocular':'chip-amarillo',
        'Parásitos externos visibles':'chip-rosa','Alopecia / pérdida de pelo':'chip-rosa',
        'Dermatitis / sarpullido':'chip-rosa','Pelaje opaco / maltratado':'chip-rosa',
        'Uñas excesivamente largas':'chip-rosa','Nódulos o masas palpables':'chip-rosa',
        'Agresividad inusual':'chip-amarillo','Miedo excesivo':'chip-amarillo',
        'Desorientación':'chip-amarillo','Pérdida de equilibrio':'chip-rojo','Nervioso / ansioso':'chip-amarillo'
      };
      var anomArr = (e.anomalias||'').split(',').map(function(s){return s.trim();}).filter(Boolean);
      document.getElementById('ver-anomalias').innerHTML = anomArr.length
        ? anomArr.map(function(a){
            return '<span class="chip '+(anomColors[a]||'chip-gray')+'">'+esc(a)+'</span>';
          }).join(' ')
        : '<span class="chip chip-gray">Sin anomalías registradas</span>';
      document.getElementById('ver-obs').textContent          = e.observaciones || '—';
      document.getElementById('ver-indicaciones').textContent = e.indicaciones  || '—';
      go('s-ver');
    })
    .catch(function(){ alert('Error de conexión.'); });
}

function confirmarEliminar(){
  if (!confirm('¿Eliminar esta evaluación? Esta acción no se puede deshacer.')) return;
  var fd = new FormData();
  fd.append('action', 'eliminar');
  fd.append('id', curEvalId);
  fetch(PHP_FILE, {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res.ok){
        document.querySelectorAll('#eval-list .eval-card').forEach(function(c){
          if (c.getAttribute('onclick') === 'verEval('+curEvalId+')') c.remove();
        });
        go('s-list');
      } else { alert('No se pudo eliminar.'); }
    })
    .catch(function(){ alert('Error de conexión.'); });
}

var pasoActual = 1;
function paso(n){
  if (n === 4) generarResumen();
  document.getElementById('paso-'+pasoActual).style.display = 'none';
  document.getElementById('paso-'+n).style.display = 'block';
  for (var i = 1; i <= 4; i++){
    var el = document.getElementById('step-'+i);
    el.className = 'step'+(i < n ? ' done' : i === n ? ' active' : '');
  }
  pasoActual = n;
  window.scrollTo({top:0, behavior:'smooth'});
}

function selEstado(btn, cls){
  document.querySelectorAll('#estado-btns .e-btn').forEach(function(b){ b.className='e-btn'; });
  btn.className = 'e-btn '+cls;
}

function toggleAnom(btn, cls){
  var active = btn.classList.contains(cls);
  if (cls === 'a-on-verde'){
    document.querySelectorAll('.anom-grid .a-btn').forEach(function(b){ b.className='a-btn'; });
  } else {
    document.querySelectorAll('.a-btn.a-on-verde').forEach(function(b){ b.className='a-btn'; });
  }
  btn.className = active ? 'a-btn' : 'a-btn '+cls;
}

function generarResumen(){
  var pet    = document.getElementById('n-pet').value   || '—';
  var owner  = document.getElementById('n-owner').value || '—';
  var svc    = document.getElementById('n-service').value || '—';
  var fecha  = document.getElementById('n-date').value  || '—';
  var hora   = document.getElementById('n-time').value  || '—';
  var peso   = document.getElementById('v-peso').value;
  var temp   = document.getElementById('v-temp').value;
  var fc     = document.getElementById('v-fc').value;
  var eBtn   = document.querySelector('#estado-btns .e-btn.e-verde,#estado-btns .e-btn.e-amarillo,#estado-btns .e-btn.e-rojo,#estado-btns .e-btn.e-azul');
  var anoms  = Array.from(document.querySelectorAll('.anom-grid .a-btn[class*="a-on"]')).map(function(b){ return b.textContent; });
  var citaId = document.getElementById('n-id-cita').value;
  document.getElementById('resumen-contenido').innerHTML =
    '<b>Mascota:</b> '+esc(pet)+' · <b>Dueño:</b> '+esc(owner)+'<br>'+
    '<b>Servicio:</b> '+esc(svc)+' · <b>Ingreso:</b> '+esc(fecha)+' '+esc(hora)+'<br>'+
    (peso?'<b>Peso:</b> '+esc(peso)+' kg · ':'')+(temp?'<b>Temp:</b> '+esc(temp)+' °C · ':'')+(fc?'<b>FC:</b> '+esc(fc)+' lpm':'')+'<br>'+
    '<b>Estado:</b> '+(eBtn?esc(eBtn.textContent):'No seleccionado')+'<br>'+
    '<b>Anomalías:</b> '+(anoms.length?esc(anoms.join(', ')):'Ninguna')+
    (citaId?'<br><b>Vinculado a cita #'+esc(citaId)+'</b>':'');
}

function guardarEval(){
  var pet   = document.getElementById('n-pet').value.trim();
  var owner = document.getElementById('n-owner').value.trim();
  if (!pet || !owner){ alert('Completa al menos el nombre de la mascota y el dueño.'); paso(1); return; }

  var btn = document.getElementById('btn-guardar');
  btn.innerHTML = '<span class="spinner"></span>Guardando...';
  btn.disabled  = true;

  var eBtn  = document.querySelector('#estado-btns .e-btn.e-verde,#estado-btns .e-btn.e-amarillo,#estado-btns .e-btn.e-rojo,#estado-btns .e-btn.e-azul');
  var anoms = Array.from(document.querySelectorAll('.anom-grid .a-btn[class*="a-on"]')).map(function(b){ return b.textContent.trim(); });
  var vObs  = document.getElementById('v-obs').value;

  var fd = new FormData();
  fd.append('action',                  'guardar');
  fd.append('nombre_mascota',          pet);
  fd.append('especie',                 document.getElementById('n-species').value);
  fd.append('raza',                    document.getElementById('n-breed').value);
  fd.append('edad',                    document.getElementById('n-age').value);
  fd.append('sexo',                    document.getElementById('n-sex').value);
  fd.append('nombre_dueno',            owner);
  fd.append('telefono',                document.getElementById('n-phone').value);
  fd.append('servicio',                document.getElementById('n-service').value);
  fd.append('veterinario',             document.getElementById('n-vet').value);
  fd.append('fecha',                   document.getElementById('n-date').value);
  fd.append('hora',                    document.getElementById('n-time').value);
  fd.append('hora_salida',             document.getElementById('n-hora-salida').value);
  fd.append('estado',                  document.getElementById('n-estado').value);
  fd.append('peso',                    document.getElementById('v-peso').value);
  fd.append('temperatura',             document.getElementById('v-temp').value);
  fd.append('frecuencia_cardiaca',     document.getElementById('v-fc').value);
  fd.append('frecuencia_respiratoria', document.getElementById('v-fr').value);
  fd.append('tlc',                     document.getElementById('v-tlc').value);
  fd.append('mucosas',                 document.getElementById('v-mucosas').value);
  fd.append('estado_general',          eBtn ? eBtn.textContent.trim() : '');
  fd.append('anomalias',               anoms.join(','));
  fd.append('observaciones',           document.getElementById('obs-detalle').value + (vObs ? '\n\n| Signos Vitales: '+vObs : ''));
  fd.append('indicaciones',            document.getElementById('obs-indicaciones').value);
  fd.append('id_cita',                 document.getElementById('n-id-cita').value);

  fetch(PHP_FILE, {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      btn.innerHTML = 'Guardar evaluación';
      btn.disabled  = false;
      if (res.ok){
        document.getElementById('nueva-ok').style.display = 'block';
        setTimeout(function(){ window.location.reload(); }, 1800);
      } else {
        var err = document.getElementById('nueva-err');
        document.getElementById('nueva-err-msg').textContent = res.error || 'Error al guardar.';
        err.style.display = 'block';
        setTimeout(function(){ err.style.display='none'; }, 3500);
      }
    })
    .catch(function(){
      btn.innerHTML = 'Guardar evaluación';
      btn.disabled  = false;
      alert('Error de conexión con el servidor.');
    });
}

function esc(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('n-date').value = new Date().toISOString().split('T')[0];
</script>
</body>
</html>