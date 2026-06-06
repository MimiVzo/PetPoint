<?php
//control_asistencia
require_once '../Conexion.php';
session_start();

if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}
$id_veterinaria = (int)$_SESSION['veterinario_id'];

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'guardar_estado') {
        $id_cita = (int)($_POST['id_cita'] ?? 0);
        $estado  = trim($_POST['estado']   ?? 'Pendiente');
        $nota    = trim($_POST['nota']     ?? '');

        $mapaEstado = [
            'Asistió'    => 'completada',
            'En curso'   => 'completada',
            'En espera'  => 'pendiente',
            'No asistió' => 'cancelada',
            'Pendiente'  => 'pendiente',
        ];
        $estadoBD = $mapaEstado[$estado] ?? 'pendiente';

        $own = $conn->prepare("SELECT id FROM citas WHERE id = ? AND id_veterinaria = ? LIMIT 1");
        $own->bind_param('ii', $id_cita, $id_veterinaria);
        $own->execute();
        if (!$own->get_result()->fetch_assoc()) {
            echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
            exit;
        }
        $own->close();

        $check = $conn->prepare("SELECT id FROM historial_citas WHERE id_cita = ? LIMIT 1");
        $check->bind_param('i', $id_cita);
        $check->execute();
        $existe = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existe) {
            $stmt = $conn->prepare("
                UPDATE historial_citas
                   SET estado         = ?,
                       notas_clinicas = ?,
                       estado_ui      = ?
                 WHERE id_cita = ?
            ");
            $stmt->bind_param('sssi', $estadoBD, $nota, $estado, $id_cita);
        } else {
            $sc = $conn->prepare("SELECT * FROM citas WHERE id = ? AND id_veterinaria = ?");
            $sc->bind_param('ii', $id_cita, $id_veterinaria);
            $sc->execute();
            $c = $sc->get_result()->fetch_assoc();
            $sc->close();

            if (!$c) {
                echo json_encode(['ok' => false, 'error' => 'Cita no encontrada']);
                exit;
            }

            $sv = $conn->prepare("SELECT establecimiento FROM veterinarios WHERE id = ?");
            $sv->bind_param('i', $id_veterinaria);
            $sv->execute();
            $vetRow = $sv->get_result()->fetch_assoc();
            $sv->close();
            $nombreVetEstab = $vetRow['establecimiento'] ?? '';
            $idusr = (int)($c['id_usuario'] ?? 0);

            $stmt = $conn->prepare("
                INSERT INTO historial_citas
                  (id_cita, id_veterinaria, id_usuario,
                   nombre_dueno, nombre_mascota, servicio,
                   fecha, hora,
                   estado, estado_ui,
                   telefono, especie, notas_clinicas, nombre_veterinaria)
                VALUES (?,?,?, ?,?,?, ?,?, ?,?, ?,?,?,?)
            ");
            $stmt->bind_param(
                'iiisssssssssss',
                $id_cita, $id_veterinaria, $idusr,
                $c['nombre_dueno'], $c['nombre_mascota'], $c['servicio'],
                $c['fecha'], $c['hora'],
                $estadoBD, $estado,
                $c['telefono'], $c['especie'], $nota, $nombreVetEstab
            );
        }

        $ok  = $stmt->execute();
        $err = $conn->error;
        $stmt->close();
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : $err]);
        exit;
    }

    if ($action === 'ver_cita') {
        $id_cita = (int)($_POST['id_cita'] ?? 0);
        $stmt    = $conn->prepare("
            SELECT c.*,
                   h.estado    AS estado_bd,
                   h.notas_clinicas,
                   COALESCE(h.estado_ui, '') AS estado_ui_label
            FROM citas c
            LEFT JOIN historial_citas h ON h.id_cita = c.id
            WHERE c.id = ? AND c.id_veterinaria = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $id_cita, $id_veterinaria);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode($row ?: ['error' => 'No encontrada']);
        exit;
    }
}

$hoy = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT c.*,
           COALESCE(h.estado,         'pendiente') AS estado_ui,
           COALESCE(h.estado_ui,      '')          AS estado_ui_label,
           COALESCE(h.notas_clinicas, '')          AS nota
    FROM citas c
    LEFT JOIN historial_citas h ON h.id_cita = c.id
    WHERE c.id_veterinaria = ? AND c.fecha = ?
    ORDER BY c.hora ASC
");
$stmt->bind_param('is', $id_veterinaria, $hoy);
$stmt->execute();
$citas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmtV = $conn->prepare("SELECT establecimiento FROM veterinarios WHERE id = ?");
$stmtV->bind_param('i', $id_veterinaria);
$stmtV->execute();
$vet = $stmtV->get_result()->fetch_assoc();
$stmtV->close();
$establecimiento = $vet['establecimiento'] ?? 'Mi veterinaria';

$total = count($citas);
$confirmadas = $pendientes = $noAsistio = 0;
foreach ($citas as $c) {
    if ($c['estado_ui'] === 'completada')    $confirmadas++;
    elseif ($c['estado_ui'] === 'cancelada') $noAsistio++;
    else                                      $pendientes++;
}

function uiEstado(string $estadoBD, string $uiLabel = ''): array {
    $mapaLabel = [
        'Asistió'    => ['label'=>'Asistió',    'badge'=>'eb-asistio', 'circle'=>'cc-ok',    'symbol'=>'✓'],
        'En curso'   => ['label'=>'En curso',   'badge'=>'eb-curso',   'circle'=>'cc-curso', 'symbol'=>'↻'],
        'En espera'  => ['label'=>'En espera',  'badge'=>'eb-espera',  'circle'=>'cc-espera','symbol'=>'⌛'],
        'No asistió' => ['label'=>'No asistió', 'badge'=>'eb-no',      'circle'=>'cc-no',    'symbol'=>'✗'],
        'Pendiente'  => ['label'=>'Pendiente',  'badge'=>'eb-pend',    'circle'=>'cc-pend',  'symbol'=>'?'],
    ];
    if ($uiLabel && isset($mapaLabel[$uiLabel])) return $mapaLabel[$uiLabel];
    $mapaBD = [
        'completada' => $mapaLabel['Asistió'],
        'cancelada'  => $mapaLabel['No asistió'],
        'pendiente'  => $mapaLabel['Pendiente'],
    ];
    return $mapaBD[$estadoBD] ?? $mapaLabel['Pendiente'];
}

function franjaHora(string $hora): string {
    $h = (int)explode(':', $hora)[0];
    if ($h < 12) return 'Mañana';
    if ($h < 18) return 'Tarde';
    return 'Noche';
}

function emojiEspecie(string $esp): string {
    $e = strtolower($esp);
    if (str_contains($e,'gato')   || str_contains($e,'cat'))    return '🐱';
    if (str_contains($e,'perro')  || str_contains($e,'dog'))    return '🐶';
    if (str_contains($e,'conejo') || str_contains($e,'rabbit')) return '🐰';
    if (str_contains($e,'ave')    || str_contains($e,'pájaro')) return '🐦';
    return '🐾';
}

function hora12(string $t): string {
    $dt = DateTime::createFromFormat('H:i:s', $t) ?: DateTime::createFromFormat('H:i', $t);
    return $dt ? $dt->format('g:i a') : $t;
}

$franjas = [];
foreach ($citas as $c) {
    $franjas[franjaHora($c['hora'])][] = $c;
}
$ordenFranjas = ['Mañana', 'Tarde', 'Noche'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="Control_asistencia.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" href="../img/Pet_Point.png" type="image/png">
<title>Confirmación de Citas</title>
</head>
<body>

<!-- ===== LISTA ===== -->
<div id="vista-lista">
  <div class="page-title">Confirmación de citas</div>
  <div class="page-sub">
    Hoy <?= date('j \d\e F', strtotime($hoy)) ?> · <?= htmlspecialchars($establecimiento) ?>
  </div>

  <div class="stats-row">
    <div class="chip ch-total"><span class="chip-num"><?= $total ?></span> Total del día</div>
    <div class="chip ch-conf"><span class="chip-num"><?= $confirmadas ?></span> Confirmadas</div>
    <div class="chip ch-pend"><span class="chip-num"><?= $pendientes ?></span> Pendientes</div>
    <div class="chip ch-no"><span class="chip-num"><?= $noAsistio ?></span> No asistió</div>
  </div>

  <?php if (empty($citas)): ?>
    <div class="empty-day">
      <div>📋</div>
      No hay citas registradas para hoy.
    </div>
  <?php else: ?>
    <p class="hint">Toca una cita para ver el detalle y actualizar su estado ›</p>

    <?php foreach ($ordenFranjas as $franja):
      if (empty($franjas[$franja])) continue;
    ?>
      <div class="hora-sep"><?= $franja ?></div>

      <?php foreach ($franjas[$franja] as $c):
        $ui    = uiEstado($c['estado_ui'], $c['estado_ui_label']);
        $emoji = emojiEspecie($c['especie']);
      ?>
        <div class="cita-card" onclick="abrirDetalle(<?= (int)$c['id'] ?>)">
          <div class="check-circle <?= $ui['circle'] ?>"><?= $ui['symbol'] ?></div>
          <div class="cita-info">
            <div class="cita-nombre">
              <?= $emoji ?> <?= htmlspecialchars($c['nombre_mascota']) ?> · <?= htmlspecialchars($c['servicio']) ?>
            </div>
            <div class="cita-detalle">
              Dueño: <?= htmlspecialchars($c['nombre_dueno']) ?> · <?= hora12($c['hora']) ?>
            </div>
          </div>
          <span class="estado-badge <?= $ui['badge'] ?>"><?= $ui['label'] ?></span>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ===== DETALLE ===== -->
<div id="vista-detalle" style="display:none">
  <div class="det-header">
    <button class="det-back" onclick="volver()">← Volver a las citas</button>
    <div class="det-top">
      <div class="det-avatar-big" id="d-avatar">🐾</div>
      <div>
        <div class="det-title" id="d-nombre">—</div>
        <div class="det-sub" id="d-sub">—</div>
      </div>
    </div>
  </div>

  <div class="det-body">
    <div class="section-card">
      <div class="section-title">Información de la cita</div>
      <div class="info-grid">
        <div class="info-item"><label>Mascota</label><span id="d-mascota">—</span></div>
        <div class="info-item"><label>Especie</label><span id="d-especie">—</span></div>
        <div class="info-item"><label>Servicio</label><span id="d-svc">—</span></div>
        <div class="info-item"><label>Hora agendada</label><span id="d-hora">—</span></div>
        <div class="info-item"><label>Dueño</label><span id="d-dueno">—</span></div>
        <div class="info-item"><label>Teléfono</label><span id="d-tel">—</span></div>
      </div>
    </div>

    <div class="section-card">
      <div class="section-title">Estado de la cita</div>
      <div class="estado-pill">
        <div class="e-dot" id="d-edot" style="background:#c97aaa"></div>
        Estado actual: <span id="d-eval">Pendiente</span>
      </div>
      <div class="estado-grid">
        <button class="est-btn" id="btn-asistio" onclick="setEstado('Asistió','#27a07a','asistio')">
          <span class="ico">✅</span>Asistió
        </button>
        <button class="est-btn" id="btn-curso" onclick="setEstado('En curso','#3b82f6','curso')">
          <span class="ico">🩺</span>En curso
        </button>
        <button class="est-btn" id="btn-espera" onclick="setEstado('En espera','#d97706','espera')">
          <span class="ico">⏳</span>En espera
        </button>
        <button class="est-btn" id="btn-no" onclick="setEstado('No asistió','#e24b4a','no')">
          <span class="ico">❌</span>No asistió
        </button>
        <button class="est-btn" id="btn-pend" onclick="setEstado('Pendiente','#c97aaa','pend')" style="grid-column:span 2">
          <span class="ico">🕐</span>Pendiente (aún no llega)
        </button>
      </div>
      <div class="box-info box-asistio" id="box-asistio">✅ <b>Asistió</b> — El dueño llegó con su mascota y el servicio fue o está siendo proporcionado correctamente.</div>
      <div class="box-info box-curso"   id="box-curso">🩺 <b>En curso</b> — La mascota está siendo atendida en este momento por el veterinario.</div>
      <div class="box-info box-espera"  id="box-espera">⏳ <b>En espera</b> — El dueño ya llegó con la mascota y están esperando en la sala.</div>
      <div class="box-info box-no"      id="box-no">❌ <b>No asistió</b> — El dueño no se presentó. Puedes contactarlo con el teléfono de la tarjeta.</div>
      <div class="box-info box-pend"    id="box-pend">🕐 <b>Pendiente</b> — La cita aún no ha llegado a su horario.</div>
    </div>

    <div class="section-card">
      <div class="section-title">Nota del veterinario (opcional)</div>
      <textarea class="nota-area" id="d-nota" placeholder="Ej: Se intentó contactar al dueño. No respondió. Se reprogramará la cita..."></textarea>
    </div>

    <input type="hidden" id="d-id-cita" value="">
    <button class="btn-guardar" id="btn-guardar" onclick="guardar()">Guardar cambios</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<div class="btn-s">
  <a href="../ServiciosAdmin.html" class="btn-servicio">
    <img alt="Regresar servicios" src="../img/Registro_Regreso.png">
  </a>
</div>

<script>
var PHP_FILE     = 'Control_asistencia.php';
var estadoActual = 'Pendiente';

function abrirDetalle(id) {
  var fd = new FormData();
  fd.append('action',  'ver_cita');
  fd.append('id_cita', id);

  fetch(PHP_FILE, {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(c){
      if (c.error){ showToast('No se pudo cargar la cita', true); return; }

      document.getElementById('d-id-cita').value          = c.id;
      document.getElementById('d-avatar').textContent     = emojiEspecie(c.especie);
      document.getElementById('d-nombre').textContent     = c.nombre_mascota;
      document.getElementById('d-sub').textContent        = c.servicio + ' · ' + c.nombre_dueno;
      document.getElementById('d-mascota').textContent    = c.nombre_mascota;
      document.getElementById('d-especie').textContent    = c.especie;
      document.getElementById('d-svc').textContent        = c.servicio;
      document.getElementById('d-hora').textContent       = hora12(c.hora);
      document.getElementById('d-dueno').textContent      = c.nombre_dueno;
      document.getElementById('d-tel').textContent        = c.telefono;
      document.getElementById('d-nota').value             = c.notas_clinicas || '';

      var mapaLabel = {
        'Asistió':    { key:'asistio', color:'#27a07a' },
        'En curso':   { key:'curso',   color:'#3b82f6' },
        'En espera':  { key:'espera',  color:'#d97706' },
        'No asistió': { key:'no',      color:'#e24b4a' },
        'Pendiente':  { key:'pend',    color:'#c97aaa' },
      };
      var mapaEnum = {
        completada: { label:'Asistió',    key:'asistio', color:'#27a07a' },
        cancelada:  { label:'No asistió', key:'no',      color:'#e24b4a' },
        pendiente:  { label:'Pendiente',  key:'pend',    color:'#c97aaa' },
      };

      var info;
      if (c.estado_ui_label && mapaLabel[c.estado_ui_label]) {
        info = { label: c.estado_ui_label, key: mapaLabel[c.estado_ui_label].key, color: mapaLabel[c.estado_ui_label].color };
      } else {
        info = mapaEnum[c.estado_bd] || mapaEnum['pendiente'];
      }

      estadoActual = info.label;
      document.getElementById('d-eval').textContent      = info.label;
      document.getElementById('d-edot').style.background = info.color;
      marcarBtn(info.key);
      mostrarBox(info.key);

      document.getElementById('vista-lista').style.display   = 'none';
      document.getElementById('vista-detalle').style.display = 'block';
      window.scrollTo(0, 0);
    })
    .catch(function(){ showToast('Error de conexión', true); });
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
  ['asistio','curso','espera','no','pend'].forEach(function(k){
    document.getElementById('btn-'+k).className = 'est-btn' + (k === key ? ' sel-'+k : '');
  });
}

function mostrarBox(key) {
  ['asistio','curso','espera','no','pend'].forEach(function(k){
    document.getElementById('box-'+k).style.display = k === key ? 'block' : 'none';
  });
}

function guardar() {
  var idCita = document.getElementById('d-id-cita').value;
  var nota   = document.getElementById('d-nota').value;
  var btn    = document.getElementById('btn-guardar');

  btn.innerHTML = '<span class="spinner"></span>Guardando...';
  btn.disabled  = true;

  var fd = new FormData();
  fd.append('action',  'guardar_estado');
  fd.append('id_cita', idCita);
  fd.append('estado',  estadoActual);
  fd.append('nota',    nota);

  fetch(PHP_FILE, {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      btn.innerHTML = 'Guardar cambios';
      btn.disabled  = false;
      if (res.ok) {
        showToast('✓ Estado actualizado correctamente');
        actualizarCardLista(idCita, estadoActual);
        // Regresar a lista y recargar para reflejar cambios
        setTimeout(function(){
          window.location.reload();
        }, 1500);
      } else {
        showToast('Error: ' + (res.error || 'No se pudo guardar'), true);
      }
    })
    .catch(function(){
      btn.innerHTML = 'Guardar cambios';
      btn.disabled  = false;
      showToast('Error de conexión', true);
    });
}

function actualizarCardLista(idCita, estadoLabel) {
  var mapaCard = {
    'Asistió':    { circle:'cc-ok',     symbol:'✓',  badge:'eb-asistio', badgeLabel:'Asistió'    },
    'En curso':   { circle:'cc-curso',  symbol:'↻',  badge:'eb-curso',   badgeLabel:'En curso'   },
    'En espera':  { circle:'cc-espera', symbol:'⌛', badge:'eb-espera',  badgeLabel:'En espera'  },
    'No asistió': { circle:'cc-no',     symbol:'✗',  badge:'eb-no',      badgeLabel:'No asistió' },
    'Pendiente':  { circle:'cc-pend',   symbol:'?',  badge:'eb-pend',    badgeLabel:'Pendiente'  },
  };
  var cfg  = mapaCard[estadoLabel] || mapaCard['Pendiente'];
  var card = document.querySelector('.cita-card[onclick="abrirDetalle('+idCita+')"]');
  if (!card) return;
  var circle = card.querySelector('.check-circle');
  var badge  = card.querySelector('.estado-badge');
  if (circle) { circle.className = 'check-circle ' + cfg.circle; circle.textContent = cfg.symbol; }
  if (badge)  { badge.className  = 'estado-badge '  + cfg.badge;  badge.textContent  = cfg.badgeLabel; }
}

function showToast(msg, isError) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'toast' + (isError ? ' error' : '');
  t.classList.add('show');
  setTimeout(function(){ t.classList.remove('show'); }, 2800);
}

function emojiEspecie(esp) {
  if (!esp) return '🐾';
  var e = esp.toLowerCase();
  if (e.includes('gato')   || e.includes('cat'))    return '🐱';
  if (e.includes('perro')  || e.includes('dog'))    return '🐶';
  if (e.includes('conejo') || e.includes('rabbit')) return '🐰';
  if (e.includes('ave')    || e.includes('pájaro')) return '🐦';
  return '🐾';
}

function hora12(t) {
  if (!t) return '—';
  var parts = t.split(':');
  var h = parseInt(parts[0]);
  var m = parts[1] || '00';
  var ampm = h >= 12 ? 'pm' : 'am';
  h = h % 12 || 12;
  return h + ':' + m + ' ' + ampm;
}
</script>
</body>
</html>