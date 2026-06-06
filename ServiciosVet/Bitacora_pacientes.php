<?php
//  Bitacora_pacientes
require_once '../Conexion.php';  
session_start();
 
if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}
$id_veterinaria = (int)$_SESSION['veterinario_id'];

// ── Zona horaria — Jalisco / México Centro (UTC-6) ───────────────────────────
date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");
// ────────────────────────────────────────────────────────────────────────────
 
//  GUARDAR CAMBIOS (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    header('Content-Type: application/json');
 
    $id_cita     = (int)($_POST['id']          ?? 0);
    $estado      = trim($_POST['estado']        ?? '');
    $hora_salida = trim($_POST['hora_salida']   ?? '');
    $nota        = trim($_POST['nota']          ?? '');
 
    if ($hora_salida === '') $hora_salida = null;
 
    // ── Mapeo estado UI → enum BD 
    $mapaEstado = [
        'Completado'  => 'completada',
        'Estable'     => 'completada',
        'En consulta' => 'pendiente',
        'Esperando'   => 'pendiente',
        'No asistió'  => 'cancelada',
    ];
    $estadoBD = $mapaEstado[$estado] ?? 'pendiente';
 
    // ── 1) Datos completos de la cita
    $sc = $conn->prepare(
        "SELECT * FROM citas WHERE id = ? AND id_veterinaria = ?"
    );
    $sc->bind_param('ii', $id_cita, $id_veterinaria);
    $sc->execute();
    $cita = $sc->get_result()->fetch_assoc();
    $sc->close();
 
    if (!$cita) {
        echo json_encode(['ok' => false, 'error' => 'Cita no encontrada']);
        exit;
    }
 
    $idusr = (int)($cita['id_usuario'] ?? 0);
 
    // ── 2) Upsert historial_citas 
    $check = $conn->prepare(
        "SELECT id FROM historial_citas WHERE id_cita = ? LIMIT 1"
    );
    $check->bind_param('i', $id_cita);
    $check->execute();
    $existe = $check->get_result()->fetch_assoc();
    $check->close();
 
    if ($existe) {
        $stmt = $conn->prepare("
            UPDATE historial_citas
               SET estado         = ?,
                   notas_clinicas = ?,
                   hora_salida    = ?,
                   estado_ui      = ?
             WHERE id_cita = ?
        ");
        $stmt->bind_param('ssssi',
            $estadoBD, $nota, $hora_salida, $estado, $id_cita
        );
 
    } else {
        $sv = $conn->prepare(
            "SELECT establecimiento FROM veterinarios WHERE id = ?"
        );
        $sv->bind_param('i', $id_veterinaria);
        $sv->execute();
        $vetRow = $sv->get_result()->fetch_assoc();
        $sv->close();
        $nombreVetEstab = $vetRow['establecimiento'] ?? '';
 
        $stmt = $conn->prepare("
            INSERT INTO historial_citas
              (id_cita, id_veterinaria, id_usuario,
               nombre_dueno, nombre_mascota, servicio,
               fecha, hora, hora_salida,
               estado, estado_ui,
               telefono, especie, notas_clinicas, nombre_veterinaria)
            VALUES (?,?,?, ?,?,?, ?,?,?, ?,?, ?,?,?,?)
        ");
        $stmt->bind_param(
            'iiissssssssssss',
            $id_cita, $id_veterinaria, $idusr,
            $cita['nombre_dueno'], $cita['nombre_mascota'], $cita['servicio'],
            $cita['fecha'], $cita['hora'], $hora_salida,
            $estadoBD, $estado,
            $cita['telefono'], $cita['especie'], $nota, $nombreVetEstab
        );
    }
 
    $ok  = $stmt->execute();
    $err = $conn->error;
    $stmt->close();
 
    if (!$ok) {
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
 
    // ── 3) Sync hora_salida en evaluaciones
    if ($hora_salida) {
        $upd = $conn->prepare("
            UPDATE evaluaciones
               SET hora_salida = ?
             WHERE CONVERT(nombre_mascota USING utf8mb4)
                 = CONVERT(? USING utf8mb4)
               AND id_veterinaria = ?
             ORDER BY id DESC LIMIT 1
        ");
        $upd->bind_param('ssi',
            $hora_salida, $cita['nombre_mascota'], $id_veterinaria
        );
        $upd->execute();
        $upd->close();
    }
 
    // ── 4) Auto-sync expedientes (solo al cerrar visita)
    if (in_array($estadoBD, ['completada', 'cancelada'])) {
        $chk = $conn->prepare("
            SELECT id FROM expedientes
             WHERE CONVERT(nombre_mascota USING utf8mb4)
                 = CONVERT(? USING utf8mb4)
               AND CONVERT(nombre_dueno USING utf8mb4)
                 = CONVERT(? USING utf8mb4)
               AND id_veterinaria = ?
             LIMIT 1
        ");
        $chk->bind_param('ssi',
            $cita['nombre_mascota'], $cita['nombre_dueno'], $id_veterinaria
        );
        $chk->execute();
        $expRow = $chk->get_result()->fetch_assoc();
        $chk->close();
 
        if ($expRow) {
            $upExp = $conn->prepare("
                UPDATE expedientes
                   SET especie  = COALESCE(NULLIF(?, ''), especie),
                       telefono = COALESCE(NULLIF(?, ''), telefono)
                 WHERE id = ?
            ");
            $upExp->bind_param('ssi',
                $cita['especie'], $cita['telefono'], $expRow['id']
            );
            $upExp->execute();
            $upExp->close();
        } else {
            $insExp = $conn->prepare("
                INSERT INTO expedientes
                  (id_veterinaria, nombre_mascota, nombre_dueno, especie, telefono)
                VALUES (?,?,?,?,?)
            ");
            $insExp->bind_param('issss',
                $id_veterinaria,
                $cita['nombre_mascota'], $cita['nombre_dueno'],
                $cita['especie'], $cita['telefono']
            );
            $insExp->execute();
            $insExp->close();
        }
    }
 
    echo json_encode(['ok' => true]);
    exit;
}
 
//  LEER DATOS — citas como fuente principal
$filtro = $_GET['filtro'] ?? 'hoy';
 
switch ($filtro) {
    case 'semana':
        $whereExtra = "AND c.fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()";
        break;
    case 'todos':
        $whereExtra = "";
        break;
    default:
        $whereExtra = "AND c.fecha = CURDATE()";
        break;
}
 
$sql = "
    SELECT
        c.id,
        c.id_veterinaria,
        c.nombre_dueno,
        c.telefono,
        c.nombre_mascota,
        c.especie,
        c.servicio,
        c.fecha,
        c.hora,
        COALESCE(h.estado,         'pendiente') AS estado_bd,
        COALESCE(h.estado_ui,      'Esperando') AS estado_ui,
        COALESCE(h.notas_clinicas, '')           AS observaciones,
        h.hora_salida,
        v.establecimiento
    FROM      citas          c
    LEFT JOIN historial_citas h ON h.id_cita = c.id
    LEFT JOIN veterinarios    v ON v.id      = c.id_veterinaria
    WHERE c.id_veterinaria = ?
    $whereExtra
    ORDER BY c.fecha DESC, c.hora ASC
";
 
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id_veterinaria);
$stmt->execute();
$registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
 
$stmtV = $conn->prepare(
    "SELECT establecimiento FROM veterinarios WHERE id = ?"
);
$stmtV->bind_param('i', $id_veterinaria);
$stmtV->execute();
$vetMain = $stmtV->get_result()->fetch_assoc();
$stmtV->close();
$nombreVet = $vetMain['establecimiento'] ?? 'Pet Point';
 
$totalHoy = $cntCompletado = $cntCancelado = $cntEsperando = 0;
$hoy = date('Y-m-d');
foreach ($registros as $r) {
    if ($r['fecha'] === $hoy) {
        $totalHoy++;
        if ($r['estado_bd'] === 'completada')    $cntCompletado++;
        elseif ($r['estado_bd'] === 'cancelada') $cntCancelado++;
        else                                     $cntEsperando++;
    }
}
 
$mesesEs  = ['enero','febrero','marzo','abril','mayo','junio',
             'julio','agosto','septiembre','octubre','noviembre','diciembre'];
$fechaHoy = (new DateTime())->format('j') . ' de ' .
            $mesesEs[(int)(new DateTime())->format('n') - 1];
 
function formatHora(?string $time): string {
    if (!$time || $time === '00:00:00') return 'Pendiente';
    $d = DateTime::createFromFormat('H:i:s', $time)
      ?: DateTime::createFromFormat('H:i', $time);
    return $d ? $d->format('g:i a') : $time;
}
function formatFecha(?string $date): string {
    if (!$date || $date === '0000-00-00') return '—';
    $d = new DateTime($date);
    $m = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return $d->format('j') . ' ' . $m[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}
function formatFechaCorta(?string $date): string {
    if (!$date || $date === '0000-00-00') return '—';
    $d = new DateTime($date);
    $m = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return $d->format('j') . ' ' . $m[(int)$d->format('n') - 1];
}
function avatarEmoji(?string $especie): string {
    $e = strtolower(trim($especie ?? ''));
    if (str_contains($e, 'gato')   || str_contains($e, 'cat'))     return '🐱';
    if (str_contains($e, 'perro')  || str_contains($e, 'dog'))     return '🐶';
    if (str_contains($e, 'conejo') || str_contains($e, 'rabbit'))  return '🐰';
    if (str_contains($e, 'ave')    || str_contains($e, 'pájaro'))  return '🐦';
    if (str_contains($e, 'hamster')|| str_contains($e, 'hámster')) return '🐹';
    return '🐾';
}
function avatarClass(int $i): string {
    return ['av-a','av-b','av-c','av-d'][$i % 4];
}
function estadoInfo(string $estadoBD, string $estadoUI = ''): array {
    $tieneEstadoUI = ($estadoUI !== '' && $estadoUI !== 'Esperando');
    $noEsPendiente = ($estadoBD !== 'pendiente');
    if ($tieneEstadoUI || $noEsPendiente) {
        switch ($estadoBD) {
            case 'completada':
                $label = $tieneEstadoUI ? $estadoUI : 'Completado';
                return ['color'=>'#27a07a','dot'=>'dot-green','class'=>'s-completado','label'=>$label,'key'=>'completado'];
            case 'cancelada':
                return ['color'=>'#e24b4a','dot'=>'dot-red','class'=>'s-noasistio','label'=>'No asistió','key'=>'noasistio'];
        }
    }
    if ($estadoUI === 'En consulta') {
        return ['color'=>'#d97706','dot'=>'dot-amber','class'=>'s-consulta','label'=>'En consulta','key'=>'consulta'];
    }
    return ['color'=>'#7c3aed','dot'=>'dot-purple','class'=>'s-esperando','label'=>'Esperando','key'=>'esperando'];
}
function badgeClass(?string $svc): string {
    $s = strtolower($svc ?? '');
    if (str_contains($s, 'consul'))  return 'bd-blue';
    if (str_contains($s, 'vacun'))   return 'bd-green';
    if (str_contains($s, 'despar'))  return 'bd-pink';
    if (str_contains($s, 'emer'))    return 'bd-red';
    return 'bd-blue';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="Bitacora_pacientes.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
<link rel="icon" href="../img/Pet_Point.png" type="image/png">
<title>Bitácora de Pacientes</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body>
 
<!-- ===== LISTA ===== -->
<div id="vista-lista">
  <h1>Bitácora de pacientes</h1>
  <p class="subtitle"><?= htmlspecialchars($fechaHoy) ?> · <?= htmlspecialchars($nombreVet) ?></p>
 
  <div class="summary">
    <div class="sum-item">
      <span class="sum-num"><?= $totalHoy ?></span> Pacientes de hoy 
    </div>
    <div class="sum-item">
      <span class="dot dot-green"></span> <?= $cntCompletado ?> Completado
    </div>
    <div class="sum-item">
      <span class="dot dot-red"></span> <?= $cntCancelado ?> No asistió
    </div>
    <div class="sum-item">
      <span class="dot dot-purple"></span> <?= $cntEsperando ?> Esperando
    </div>
  </div>
 
  <div class="filters">
    <a class="filter-btn <?= $filtro === 'hoy'    ? 'active' : '' ?>" href="?filtro=hoy">Hoy</a>
    <a class="filter-btn <?= $filtro === 'semana' ? 'active' : '' ?>" href="?filtro=semana">Esta semana</a>
    <a class="filter-btn <?= $filtro === 'todos'  ? 'active' : '' ?>" href="?filtro=todos">Todos</a>
  </div>
 
  <p class="hint">Toca un paciente para ver el detalle y actualizar su estado ›</p>
 
  <div id="lista">
  <?php if (empty($registros)): ?>
    <div class="empty">😿 No hay registros para este filtro</div>
  <?php else: ?>
    <?php foreach ($registros as $i => $r):
      $est         = estadoInfo($r['estado_bd'], $r['estado_ui']);
      $emoji       = avatarEmoji($r['especie']);
      $avCls       = avatarClass($i);
      $badge       = badgeClass($r['servicio']);
      $horaIngreso = formatFechaCorta($r['fecha']) . ' · ' . formatHora($r['hora']);
      $horaSalida  = ($r['hora_salida'] && $r['hora_salida'] !== '00:00:00')
                     ? formatFechaCorta($r['fecha']) . ' · ' . formatHora($r['hora_salida'])
                     : null;
    ?>
    <div class="patient-card" onclick="abrirDetalle(<?= (int)$r['id'] ?>)">
      <div class="avatar <?= $avCls ?>"><?= $emoji ?></div>
      <div class="info">
        <div class="name-row">
          <?= htmlspecialchars($r['nombre_mascota']) ?>
          <span class="badge <?= $badge ?>"><?= htmlspecialchars($r['servicio']) ?></span>
        </div>
        <div class="meta">
          Dueño: <b><?= htmlspecialchars($r['nombre_dueno']) ?></b><br>
          Ingreso: <b><?= $horaIngreso ?></b> ·
          Salida:
          <?php if ($horaSalida): ?>
            <b><?= $horaSalida ?></b>
          <?php else: ?>
            <span class="pend-purple">Pendiente</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="status-right <?= $est['class'] ?>">
        <span class="dot <?= $est['dot'] ?>"></span>
        <?= $est['label'] ?> ›
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
</div>
 
<!-- ===== DETALLE ===== -->
<div id="vista-detalle">
  <div class="det-header">
    <button class="det-back" onclick="volver()"> <  Volver a la lista</button>
    <div class="det-top">
      <div class="det-avatar-big" id="d-avatar">🐾</div>
      <div>
        <div class="det-title" id="d-nombre">—</div>
        <div class="det-sub"   id="d-sub">—</div>
      </div>
    </div>
  </div>
 
  <div class="det-body">
    <div class="section-card">
      <div class="section-title">Información de la visita</div>
      <div class="info-grid">
        <div class="info-item"><label>Especie</label><span id="d-especie">—</span></div>
        <div class="info-item"><label>Servicio</label><span id="d-svc">—</span></div>
        <div class="info-item"><label>Hora de ingreso</label><span id="d-ingreso">—</span></div>
        <div class="info-item"><label>Dueño</label><span id="d-dueno">—</span></div>
        <div class="info-item"><label>Teléfono</label><span id="d-tel">—</span></div>
        <div class="info-item"><label>Fecha</label><span id="d-fecha">—</span></div>
      </div>
    </div>
 
    <div class="section-card">
      <div class="section-title">Estado de la visita</div>
      <div class="estado-pill">
        <div class="e-dot" id="d-edot" style="background:#7c3aed"></div>
        Estado actual: <span id="d-eval">—</span>
      </div>
      <div class="estado-grid">
        <button class="est-btn" id="btn-completado"
                onclick="setEstado('Completado','#27a07a','completado')">
          <span class="ico">✅</span>Completado
        </button>
        <button class="est-btn" id="btn-consulta"
                onclick="setEstado('En consulta','#d97706','consulta')">
          <span class="ico">🩺</span>En consulta
        </button>
        <button class="est-btn" id="btn-esperando"
                onclick="setEstado('Esperando','#7c3aed','esperando')">
          <span class="ico">⏳</span>Esperando
        </button>
        <button class="est-btn" id="btn-noasistio"
                onclick="setEstado('No asistió','#ff0000','noasistio')">
          <span class="ico">❌</span>No asistió
        </button>
      </div>
      <div class="box-info box-esperando"  id="box-esperando">
        💜 <b>"Esperando"</b><br>
        La mascota llegó al establecimiento y está en sala de espera, pero aún no ha sido llamada a consulta.
      </div>
      <div class="box-info box-consulta"   id="box-consulta">
        🩺 <b>En consulta</b><br>
        La mascota está siendo atendida en este momento por el veterinario.
      </div>
      <div class="box-info box-completado" id="box-completado">
        ✅ <b>Completado</b><br>
        El servicio fue prestado exitosamente. Al guardar se actualizará el expediente e historial automáticamente.
      </div>
      <div class="box-info box-noasistio"  id="box-noasistio">
        ❌ <b>No asistió</b><br>
        El dueño no se presentó. Considera contactarlo con el teléfono registrado.
      </div>
    </div>
 
    <div class="section-card hora-wrap">
      <div class="section-title">Hora de salida</div>
      <p>Actualízala cuando el paciente salga del establecimiento.</p>
      <div class="hora-row">
        <input type="time" class="hora-input" id="d-horasalida">
        <span class="hora-est">Agendada: <b id="d-horaest">—</b></span>
      </div>
    </div>
 
    <div class="section-card">
      <div class="section-title">Nota del veterinario (opcional)</div>
      <textarea class="nota-area" id="d-nota"
        placeholder="Ej: Paciente tranquilo. Se aplicó vacuna sin complicaciones…"></textarea>
    </div>
 
    <button class="btn-guardar" id="btn-guardar" onclick="guardar()">
       Guardar cambios
    </button>
    <p class="sync-note">
      Al marcar como <span>Completado</span> o <span>No asistió</span>,
      el expediente e historial del paciente se actualizan automáticamente.
    </p>
  </div>
</div>
 
<div class="toast" id="toast"></div>

<!-- BTN REGRESO -->
<div class="btn-s">
    <a href="../ServiciosAdmin.html" class="btn-servicio">
    <img alt="Regresar servios" src="../img/Registro_Regreso.png">
    </a>
</div>

<!-- ===== DATOS PHP → JS ===== -->
<script>
var REGISTROS = {};
<?php foreach ($registros as $i => $r):
    $est             = estadoInfo($r['estado_bd'], $r['estado_ui']);
    $emoji           = avatarEmoji($r['especie']);
    $horaIngreso     = formatFechaCorta($r['fecha']) . ' · ' . formatHora($r['hora']);
    $horaSalidaInput = '';
    if ($r['hora_salida'] && $r['hora_salida'] !== '00:00:00') {
        $d = DateTime::createFromFormat('H:i:s', $r['hora_salida']);
        $horaSalidaInput = $d ? $d->format('H:i') : '';
    }
    $horaAgendada = formatHora($r['hora']);
?>
REGISTROS[<?= (int)$r['id'] ?>] = {
  id:         <?= (int)$r['id'] ?>,
  avatar:     <?= json_encode($emoji,                   JSON_UNESCAPED_UNICODE) ?>,
  nombre:     <?= json_encode($r['nombre_mascota'],     JSON_UNESCAPED_UNICODE) ?>,
  svc:        <?= json_encode($r['servicio'],           JSON_UNESCAPED_UNICODE) ?>,
  dueno:      <?= json_encode($r['nombre_dueno'],       JSON_UNESCAPED_UNICODE) ?>,
  especie:    <?= json_encode($r['especie'],            JSON_UNESCAPED_UNICODE) ?>,
  ingreso:    <?= json_encode($horaIngreso,             JSON_UNESCAPED_UNICODE) ?>,
  tel:        <?= json_encode($r['telefono'],           JSON_UNESCAPED_UNICODE) ?>,
  fecha:      <?= json_encode(formatFecha($r['fecha']), JSON_UNESCAPED_UNICODE) ?>,
  estado:     <?= json_encode($est['label'],            JSON_UNESCAPED_UNICODE) ?>,
  color:      <?= json_encode($est['color']) ?>,
  key:        <?= json_encode($est['key']) ?>,
  horaSalida: <?= json_encode($horaSalidaInput) ?>,
  horaEst:    <?= json_encode($horaAgendada,            JSON_UNESCAPED_UNICODE) ?>,
  nota:       <?= json_encode($r['observaciones'],      JSON_UNESCAPED_UNICODE) ?>
};
<?php endforeach; ?>
 
var idActual     = null;
var estadoActual = '';
var PHP_FILE     = window.location.href.split('?')[0];
 
function abrirDetalle(id) {
  var p = REGISTROS[id];
  if (!p) return;
  idActual     = id;
  estadoActual = p.estado;
 
  document.getElementById('d-avatar').textContent    = p.avatar;
  document.getElementById('d-nombre').textContent    = p.nombre;
  document.getElementById('d-sub').textContent       = p.svc + ' · ' + p.dueno;
  document.getElementById('d-especie').textContent   = p.especie;
  document.getElementById('d-svc').textContent       = p.svc;
  document.getElementById('d-ingreso').textContent   = p.ingreso;
  document.getElementById('d-dueno').textContent     = p.dueno;
  document.getElementById('d-tel').textContent       = p.tel;
  document.getElementById('d-fecha').textContent     = p.fecha;
  document.getElementById('d-eval').textContent      = p.estado;
  document.getElementById('d-edot').style.background = p.color;
  document.getElementById('d-horasalida').value      = p.horaSalida || '';
  document.getElementById('d-horaest').textContent   = p.horaEst;
  document.getElementById('d-nota').value            = p.nota || '';
 
  marcarBtn(p.key);
  mostrarBox(p.key);
 
  document.getElementById('vista-lista').style.display   = 'none';
  document.getElementById('vista-detalle').style.display = 'block';
  window.scrollTo(0, 0);
}
 
function volver() {
  document.getElementById('vista-lista').style.display   = 'block';
  document.getElementById('vista-detalle').style.display = 'none';
  window.scrollTo(0, 0);
}
 
function setEstado(val, color, key) {
  estadoActual = val;
  document.getElementById('d-eval').textContent      = val;
  document.getElementById('d-edot').style.background = color;
  marcarBtn(key);
  mostrarBox(key);
}
 
function marcarBtn(key) {
  ['completado','consulta','esperando','noasistio'].forEach(function(k) {
    document.getElementById('btn-' + k).className =
      'est-btn' + (k === key ? ' sel-' + k : '');
  });
}
 
function mostrarBox(key) {
  ['esperando','consulta','completado','noasistio'].forEach(function(k) {
    document.getElementById('box-' + k).style.display = (k === key) ? 'block' : 'none';
  });
}
 
function guardar() {
  if (!idActual) return;
 
  var horasalida = document.getElementById('d-horasalida').value;
  var nota       = document.getElementById('d-nota').value;
  var btn        = document.getElementById('btn-guardar');
 
  btn.innerHTML = '<span class="spinner"></span>Guardando...';
  btn.disabled  = true;
 
  var body = new URLSearchParams({
    accion:      'guardar',
    id:          idActual,
    estado:      estadoActual,
    hora_salida: horasalida,
    nota:        nota
  });
 
  fetch(PHP_FILE, { method: 'POST', body: body })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.innerHTML = 'Guardar cambios';
      btn.disabled  = false;
 
      var t = document.getElementById('toast');
 
      if (data.ok) {
        var syncMsg = (estadoActual === 'Completado' || estadoActual === 'No asistió')
          ? '✓ Guardado · Expediente e historial actualizados'
          : '✓ Cambios guardados correctamente';
 
        t.textContent = syncMsg;
        t.className   = 'toast show';

        // ── CAMBIO: tras 2 s muestra el toast, luego recarga directo a la lista
        setTimeout(function() {
          window.location.href = PHP_FILE + '?filtro=<?= $filtro ?>';
        }, 2000);

      } else {
        t.textContent = '✗ ' + (data.error || 'Error al guardar');
        t.className   = 'toast error show';
        setTimeout(function() { t.className = 'toast'; }, 3000);
      }
    })
    .catch(function() {
      btn.innerHTML = 'Guardar cambios';
      btn.disabled  = false;
      var t = document.getElementById('toast');
      t.textContent = '✗ Error de conexión';
      t.className   = 'toast error show';
      setTimeout(function() { t.className = 'toast'; }, 2500);
    });
}
</script>
</body>
</html>