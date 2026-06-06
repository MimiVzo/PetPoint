<?php

session_start();

// ── Conexión a BD
$host   = 'localhost';
$db     = 'pet_point';
$user   = 'root';
$pass   = '';
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=$charset",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('Error de conexión: ' . $e->getMessage());
}

// ── Usuario en sesión 
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
$id_usuario = (int) $_SESSION['usuario_id'];

// ── Datos del usuario  (agregamos imagen y genero)
$stmtU = $pdo->prepare("SELECT nombre, apellido, correo, imagen, genero FROM usuarios WHERE id = ?");
$stmtU->execute([$id_usuario]);
$usuario = $stmtU->fetch();
if (!$usuario) {
    die('Usuario no encontrado.');
}
$nombre_completo = htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']);
$correo          = htmlspecialchars($usuario['correo']);

// ── Mascotas únicas del usuario 
$stmtM = $pdo->prepare("
    SELECT
        c.nombre_mascota,
        c.especie,
        COUNT(*)                                         AS total_citas,
        SUM(CASE WHEN hc.estado = 'completada' THEN 1
                 WHEN hc.estado IS NULL
                   AND c.fecha < CURDATE()              THEN 1
                 ELSE 0 END)                             AS asistidas,
        SUM(CASE WHEN hc.estado = 'cancelada'   THEN 1
                 ELSE 0 END)                             AS canceladas
    FROM citas c
    LEFT JOIN historial_citas hc ON hc.id_cita = c.id
    WHERE c.id_usuario = ?
    GROUP BY c.nombre_mascota, c.especie
    ORDER BY c.nombre_mascota ASC
");
$stmtM->execute([$id_usuario]);
$mascotas = $stmtM->fetchAll();

$total_citas_usuario = 0;
foreach ($mascotas as $m) $total_citas_usuario += $m['total_citas'];

// ── Citas detalladas por mascota 
$stmtC = $pdo->prepare("
    SELECT
        c.nombre_mascota,
        c.especie,
        c.servicio,
        c.fecha,
        c.hora,
        v.establecimiento  AS veterinaria,
        COALESCE(hc.estado, 'pendiente') AS estado
    FROM citas c
    LEFT JOIN historial_citas hc ON hc.id_cita = c.id
    LEFT JOIN veterinarios    v  ON v.id        = c.id_veterinaria
    WHERE c.id_usuario = ?
    ORDER BY c.fecha DESC, c.hora DESC
");
$stmtC->execute([$id_usuario]);
$todas_citas = $stmtC->fetchAll();

$citas_por_mascota = [];
foreach ($todas_citas as $c) {
    $key = strtolower(trim($c['nombre_mascota']));
    $citas_por_mascota[$key][] = $c;
}

// ── Helpers PHP 
function emoji_especie(string $esp): string {
    $esp = strtolower(trim($esp));
    if (str_contains($esp, 'perro') || str_contains($esp, 'dog'))   return '🐶';
    if (str_contains($esp, 'gato') || str_contains($esp, 'cat'))    return '🐱';
    if (str_contains($esp, 'conejo') || str_contains($esp, 'rabbit')) return '🐰';
    if (str_contains($esp, 'hamster') || str_contains($esp, 'hámster')) return '🐹';
    if (str_contains($esp, 'ave') || str_contains($esp, 'pájaro'))  return '🐦';
    if (str_contains($esp, 'pez') || str_contains($esp, 'fish'))    return '🐟';
    if (str_contains($esp, 'tortuga'))                               return '🐢';
    return '🐾';
}
// Colores cambian
$paletas = [
    ['bg_card'=>'#fff0e0','bg_header'=>'linear-gradient(135deg,#d4719a,#b85a8a)'],
    ['bg_card'=>'#fde8f4','bg_header'=>'linear-gradient(135deg,#c084d4,#9a4db5)'],
    ['bg_card'=>'#e8f0fd','bg_header'=>'linear-gradient(135deg,#6b8cca,#4a6db5)'],
    ['bg_card'=>'#e8fdf0','bg_header'=>'linear-gradient(135deg,#4ab58a,#2a8a6a)'],
    ['bg_card'=>'#fdf4e0','bg_header'=>'linear-gradient(135deg,#e0a030,#c07810)'],
];

$citas_json = [];
foreach ($citas_por_mascota as $key => $arr) {
    $citas_json[$key] = array_map(function($c) {
        $meses = ['enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $ts = strtotime($c['fecha']);
        $dia = date('j', $ts);
        $mes = $meses[(int)date('n', $ts) - 1];
        $anio = date('Y', $ts);
        $hora12 = date('g:i a', strtotime($c['hora']));
        return [
            'fecha'       => "$dia $mes $anio · $hora12",
            'servicio'    => htmlspecialchars($c['servicio']),
            'veterinaria' => htmlspecialchars($c['veterinaria'] ?? 'Veterinaria'),
            'estado'      => $c['estado'],
        ];
    }, $arr);
}
$citas_json_str = json_encode($citas_json, JSON_UNESCAPED_UNICODE);

$mascotas_json = [];
foreach ($mascotas as $i => $m) {
    $key = strtolower(trim($m['nombre_mascota']));
    $p   = $paletas[$i % count($paletas)];
    $mascotas_json[$key] = [
        'nombre'     => htmlspecialchars($m['nombre_mascota']),
        'especie'    => htmlspecialchars($m['especie']),
        'emoji'      => emoji_especie($m['especie']),
        'bg_card'    => $p['bg_card'],
        'bg_header'  => $p['bg_header'],
        'total'      => (int)$m['total_citas'],
        'asistidas'  => (int)$m['asistidas'],
        'canceladas' => (int)$m['canceladas'],
    ];
}
$mascotas_json_str = json_encode($mascotas_json, JSON_UNESCAPED_UNICODE);

// ── Avatar del dueño ────────────────────────────────────────────────────────
// Prioridad: columna imagen de BD → emoji por género
$img_bd = trim($usuario['imagen'] ?? '');
$genero = $usuario['genero'] ?? '';

if ($img_bd !== '') {
    // Si la BD guarda la ruta relativa (ej. "uploads/foto.jpg"), la usamos tal cual.
    // Si guarda la URL completa también funciona.
    $avatar_html = '<img src="' . htmlspecialchars($img_bd) . '"
                         style="width:90px;height:90px;border-radius:50%;object-fit:cover;"
                         alt="Foto de perfil">';
} else {
    $avatar_html = match($genero) {
        'Mujer'  => '👩',
        'Hombre' => '👨',
        default  => '🧑',
    };
}
// ────────────────────────────────────────────────────────────────────────────
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
 <link rel="stylesheet" href="CSS/Historial_citas.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" href="img/Pet_Point.png" type="imagen/png"> 
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
<title>Historial de mascotas</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

<!-- ===== VISTA: LISTADO DE MASCOTAS ===== -->
<div id="vista-mascotas">
  <div class="page-title">Mis mascotas</div>
  <div class="page-sub"><?= $nombre_completo ?> · Selecciona una mascota para ver su historial</div>

  <!-- Perfil del dueño -->
  <div class="owner-card">
    <div class="owner-avatar">
      <?= $avatar_html ?>
    </div>
    <div class="owner-info">
      <div class="owner-name"><?= $nombre_completo ?></div>
      <div class="owner-email"><?= $correo ?></div>
      <div class="owner-stats">
        <span class="o-stat"><?= count($mascotas) ?> mascota<?= count($mascotas) !== 1 ? 's' : '' ?> registrada<?= count($mascotas) !== 1 ? 's' : '' ?></span>
        <span class="o-stat">· <?= $total_citas_usuario ?> cita<?= $total_citas_usuario !== 1 ? 's' : '' ?> en total</span>
      </div>
    </div>
  </div>

  <div class="section-lbl">Mascotas registradas</div>
  <?php if (!empty($mascotas)): ?>
  <p class="hint">Toca una mascota para ver su historial de citas ›</p>
  <?php endif; ?>

  <div class="mascotas-grid">
  <?php if (empty($mascotas)): ?>
    <div class="empty-mascotas">
      <div class="ico">🐾</div>
      <p>Aún no tienes citas registradas.<br>¡Agenda la primera para tu mascota!</p>
    </div>
  <?php else: ?>
    <?php foreach ($mascotas as $i => $m):
      $key    = strtolower(trim($m['nombre_mascota']));
      $p      = $paletas[$i % count($paletas)];
      $emoji  = emoji_especie($m['especie']);
      $total  = (int)$m['total_citas'];
      $asist  = (int)$m['asistidas'];
      $cancel = (int)$m['canceladas'];
    ?>
    <div class="mascota-card" onclick="abrirHistorial('<?= htmlspecialchars(addslashes($key)) ?>')">
      <div class="m-emoji" style="background:<?= $p['bg_card'] ?>"><?= $emoji ?></div>
      <div class="m-nombre"><?= htmlspecialchars($m['nombre_mascota']) ?></div>
      <div class="m-raza"><?= htmlspecialchars(ucfirst($m['especie'])) ?></div>
      <div class="m-chips">
        <span class="m-chip chip-gray"><?= $total ?> cita<?= $total !== 1 ? 's' : '' ?></span>
        <?php if ($asist > 0): ?>
          <span class="m-chip chip-green"><?= $asist ?> asistida<?= $asist !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <?php if ($cancel > 0): ?>
          <span class="m-chip chip-red"><?= $cancel ?> cancelada<?= $cancel !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>
      <span class="ver-hist">Ver historial →</span>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
</div>

<!-- ===== VISTA: HISTORIAL DE MASCOTA ===== -->
<div id="vista-historial">
  <div class="det-header" id="det-header-bg" style="background:#d4719a">
    <button class="det-back" onclick="volver()">← Mis mascotas</button>
    <div class="det-top">
      <div class="det-avatar-big" id="h-avatar">🐾</div>
      <div>
        <div class="det-title" id="h-nombre">—</div>
        <div class="det-sub"  id="h-sub">—</div>
      </div>
    </div>
  </div>

  <div class="det-body">
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-num sn-pink"  id="h-total">0</div>
        <div class="stat-lbl">Citas agendadas</div>
      </div>
      <div class="stat-card">
        <div class="stat-num sn-green" id="h-asist">0</div>
        <div class="stat-lbl">Citas asistidas</div>
      </div>
      <div class="stat-card">
        <div class="stat-num sn-red"   id="h-cancel">0</div>
        <div class="stat-lbl">Canceladas</div>
      </div>
    </div>

    <div class="hist-label">Historial de asistencia</div>
    <div class="timeline" id="h-timeline"></div>
  </div>
</div>

<!-- BTN REGRESO -->
<div class="btn-s">
    <a href="servicios.html" class="btn-servicio">
    <img alt="Regresar servios" src="img/Registro_Regreso.png">
    </a>
</div>


<script>
const MASCOTAS = <?= $mascotas_json_str ?>;
const CITAS    = <?= $citas_json_str ?>;

const ESTADO_LABEL = {
  completada : { texto:'Asistió',   clase:'b-completada' },
  cancelada  : { texto:'Cancelada', clase:'b-cancelada'  },
  pendiente  : { texto:'Pendiente', clase:'b-pendiente'  },
};

function abrirHistorial(key) {
  const m     = MASCOTAS[key];
  const citas = CITAS[key] || [];
  if (!m) return;

  document.getElementById('det-header-bg').style.background = m.bg_header;
  document.getElementById('h-avatar').textContent = m.emoji;
  document.getElementById('h-nombre').textContent = m.nombre;
  document.getElementById('h-sub').textContent    = m.especie;

  document.getElementById('h-total').textContent  = m.total;
  document.getElementById('h-asist').textContent  = m.asistidas;
  document.getElementById('h-cancel').textContent = m.canceladas;

  const tl = document.getElementById('h-timeline');
  if (citas.length === 0) {
    tl.innerHTML = `<div class="empty-state">
      <div class="ico">📋</div>
      <p>Aún no hay citas registradas para esta mascota.</p>
    </div>`;
  } else {
    tl.innerHTML = citas.map(c => {
      const est = ESTADO_LABEL[c.estado] || ESTADO_LABEL.pendiente;
      return `
        <div class="tl-item">
          <div class="tl-dot ${c.estado}"></div>
          <div class="tl-box">
            <div class="tl-date">${c.fecha}</div>
            <div class="tl-row">
              <div>
                <div class="tl-name">${m.nombre} — ${c.servicio}</div>
                <div class="tl-place">📍 ${c.veterinaria}</div>
              </div>
              <span class="badge ${est.clase}">${est.texto}</span>
            </div>
          </div>
        </div>`;
    }).join('');
  }

  document.getElementById('vista-mascotas').style.display  = 'none';
  document.getElementById('vista-historial').style.display = 'block';
  window.scrollTo(0, 0);
}

function volver() {
  document.getElementById('vista-mascotas').style.display  = 'block';
  document.getElementById('vista-historial').style.display = 'none';
  window.scrollTo(0, 0);
}
</script>
</body>
</html>