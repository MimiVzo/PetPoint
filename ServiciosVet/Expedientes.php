<?php
session_start();
include("../Conexion.php"); 

if (!isset($_SESSION['veterinario_id'])) {
    header("Location: iniciosesion.php");
    exit();
}
$id_vet = (int)$_SESSION['veterinario_id'];

function jsonRes($ok, $msg = '', $rows = null) {
    header('Content-Type: application/json');
    $out = ['ok' => $ok, 'msg' => $msg];
    if ($rows !== null) $out['rows'] = $rows;
    echo json_encode($out);
    exit;
}
function fechaCorta($d) {
    if (!$d || $d === '0000-00-00') return '—';
    $dt = new DateTime($d);
    $m  = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return $dt->format('j') . ' ' . $m[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
}
function horaFmt($t) {
    if (!$t || $t === '00:00:00') return '';
    $d = DateTime::createFromFormat('H:i:s', $t);
    return $d ? $d->format('g:i a') : $t;
}
function badgeServicio($svc) {
    $s = strtolower($svc ?? '');
    if (str_contains($s,'vacun'))                               return 'bp';
    if (str_contains($s,'chequ') || str_contains($s,'consul')) return 'bb';
    if (str_contains($s,'despar'))                              return 'bg';
    if (str_contains($s,'urgent') || str_contains($s,'emerg')) return 'bo';
    if (str_contains($s,'ciruj'))                               return 'br';
    return 'bb';
}
function emojiEspecie($esp) {
    $e = strtolower(trim($esp ?? ''));
    if (str_contains($e,'perro')) return '🐶';
    if (str_contains($e,'gato'))  return '🐱';
    if (str_contains($e,'conejo'))return '🐰';
    if (str_contains($e,'ave') || str_contains($e,'pájaro')) return '🐦';
    if (str_contains($e,'reptil'))return '🦎';
    return '🐾';
}


//  ACCIONES AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ── Historial: lee de evaluaciones + historial_citas
    if ($accion === 'listar_historial') {
        $nombre = trim($_POST['nombre_mascota'] ?? '');

        $stmt = $pdo->prepare("
            SELECT id, servicio, fecha, hora, veterinario AS nombre_veterinaria,
                   anomalias AS condiciones,
                   CONCAT_WS(' | ',
                       IF(estado_general <>'', CONCAT('Estado: ', estado_general), NULL),
                       IF(anomalias <>'',      CONCAT('Anomalías: ', anomalias),   NULL),
                       IF(indicaciones <>'',   CONCAT('Indicaciones: ', indicaciones), NULL),
                       IF(observaciones <>'',  observaciones, NULL)
                   ) AS notas_clinicas,
                   peso,
                   'evaluacion' AS origen
            FROM evaluaciones
            WHERE nombre_mascota = ? AND id_veterinaria = ?
              AND estado NOT IN ('Esperando','En consulta')
            ORDER BY fecha DESC, hora DESC
        ");
        $stmt->execute([$nombre, $id_vet]);
        $deEvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $pdo->prepare("
            SELECT h.id, h.servicio, h.fecha, h.hora,
                   h.nombre_veterinaria, h.condiciones, h.notas_clinicas,
                   h.peso, 'historial' AS origen
            FROM historial_citas h
            WHERE h.nombre_mascota = ? AND h.id_veterinaria = ?
              AND (h.id_cita IS NULL OR h.id_cita NOT IN (
                  SELECT id FROM evaluaciones WHERE nombre_mascota=? AND id_veterinaria=?
              ))
            ORDER BY h.fecha DESC, h.hora DESC
        ");
        $stmt2->execute([$nombre, $id_vet, $nombre, $id_vet]);
        $deHist = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $rows = array_merge($deEvals, $deHist);
        usort($rows, fn($a,$b) => strcmp($b['fecha'].$b['hora'], $a['fecha'].$a['hora']));

        $meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
        foreach ($rows as &$r) {
            $dt = new DateTime($r['fecha']);
            $r['fecha_fmt'] = $dt->format('j').' '.$meses[(int)$dt->format('n')-1].' '.$dt->format('Y');
            $r['hora']      = horaFmt($r['hora']);
            $r['badge']     = badgeServicio($r['servicio']);
            $r['peso']      = $r['peso'] ? number_format((float)$r['peso'], 1) : '';
            $r['condiciones']        = htmlspecialchars($r['condiciones']        ?? '');
            $r['notas_clinicas']     = htmlspecialchars($r['notas_clinicas']     ?? '');
            $r['nombre_veterinaria'] = htmlspecialchars($r['nombre_veterinaria'] ?? '');
            $r['servicio']           = htmlspecialchars($r['servicio']);
        }
        jsonRes(true, '', $rows);
    }

    // ── Guardar / actualizar expediente
    if ($accion === 'guardar_expediente') {
        $id             = (int)($_POST['id']             ?? 0);
        $nombre_mascota = trim($_POST['nombre_mascota']  ?? '');
        $nombre_dueno   = trim($_POST['nombre_dueno']    ?? '');
        $especie        = trim($_POST['especie']         ?? '');
        $telefono       = trim($_POST['telefono']        ?? '');
        $raza           = trim($_POST['raza']            ?? '');
        $edad           = trim($_POST['edad']            ?? '');
        $peso           = ($_POST['peso'] ?? '') !== '' ? floatval($_POST['peso']) : null;
        $sexo           = trim($_POST['sexo']            ?? '');
        $caracteristicas= trim($_POST['caracteristicas'] ?? '');
        $condiciones    = trim($_POST['condiciones']     ?? '');
        $notas_clinicas = trim($_POST['notas_clinicas']  ?? '');

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE expedientes
                SET nombre_mascota=?, nombre_dueno=?, especie=?, telefono=?,
                    raza=?, edad=?, peso=?, sexo=?,
                    caracteristicas=?, condiciones=?, notas_clinicas=?
                WHERE id=? AND id_veterinaria=?
            ");
            $stmt->execute([
                $nombre_mascota, $nombre_dueno, $especie, $telefono,
                $raza, $edad, $peso, $sexo,
                $caracteristicas, $condiciones, $notas_clinicas,
                $id, $id_vet
            ]);
            jsonRes(true, $id);
        } else {
            $chk = $pdo->prepare("
                SELECT id FROM expedientes
                WHERE nombre_mascota=? AND nombre_dueno=? AND id_veterinaria=? LIMIT 1
            ");
            $chk->execute([$nombre_mascota, $nombre_dueno, $id_vet]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $stmt = $pdo->prepare("
                    UPDATE expedientes
                    SET especie=?, telefono=?, raza=?, edad=?, peso=?, sexo=?,
                        caracteristicas=?, condiciones=?, notas_clinicas=?
                    WHERE id=? AND id_veterinaria=?
                ");
                $stmt->execute([
                    $especie, $telefono, $raza, $edad, $peso, $sexo,
                    $caracteristicas, $condiciones, $notas_clinicas,
                    $row['id'], $id_vet
                ]);
                jsonRes(true, $row['id']);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO expedientes
                        (id_veterinaria, nombre_mascota, nombre_dueno, especie, telefono,
                         raza, edad, peso, sexo, caracteristicas, condiciones, notas_clinicas)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $id_vet, $nombre_mascota, $nombre_dueno, $especie, $telefono,
                    $raza, $edad, $peso, $sexo,
                    $caracteristicas, $condiciones, $notas_clinicas
                ]);
                jsonRes(true, (int)$pdo->lastInsertId());
            }
        }
    }

    // ── Agregar registro manual al historial
    if ($accion === 'agregar_historial') {
        $nombre_mascota = trim($_POST['nombre_mascota'] ?? '');
        $nombre_dueno   = trim($_POST['nombre_dueno']   ?? '');
        $especie        = trim($_POST['especie']        ?? '');
        $raza           = trim($_POST['raza']           ?? '');
        $edad           = trim($_POST['edad']           ?? '');
        $telefono       = trim($_POST['telefono']       ?? '');
        $peso           = ($_POST['peso'] ?? '') !== '' ? floatval($_POST['peso']) : null;
        $servicio       = trim($_POST['servicio']       ?? '');
        $fecha          = trim($_POST['fecha']          ?? date('Y-m-d'));
        $hora           = trim($_POST['hora']           ?? '00:00:00');
        $condiciones    = trim($_POST['condiciones']    ?? '');
        $notas          = trim($_POST['notas']          ?? '');
        $nombre_vet     = trim($_POST['nombre_vet']     ?? '');

        $stmt = $pdo->prepare("
            INSERT INTO historial_citas
                (id_veterinaria, nombre_mascota, nombre_dueno, especie, telefono,
                 raza, edad, peso, servicio, fecha, hora,
                 condiciones, notas_clinicas, nombre_veterinaria, estado)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'completada')
        ");
        $stmt->execute([
            $id_vet, $nombre_mascota, $nombre_dueno, $especie, $telefono,
            $raza, $edad, $peso, $servicio, $fecha, $hora,
            $condiciones, $notas, $nombre_vet
        ]);
        jsonRes(true, (int)$pdo->lastInsertId());
    }

    // ── Eliminar registro de historial
    if ($accion === 'eliminar_historial') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            DELETE FROM historial_citas
            WHERE id = ? AND id_veterinaria = ?
        ");
        $stmt->execute([$id, $id_vet]);
        jsonRes(true);
    }

    jsonRes(false, 'Acción desconocida');
}

//  LEER EXPEDIENTES (con expediente formal)
$stmt = $pdo->prepare("
    SELECT e.*,
           COUNT(DISTINCT ev.id)  AS total_visitas,
           MAX(ev.fecha)          AS ultima_visita
    FROM expedientes e
    LEFT JOIN evaluaciones ev
           ON ev.nombre_mascota = e.nombre_mascota
          AND ev.id_veterinaria  = e.id_veterinaria
          AND ev.estado NOT IN ('Esperando','En consulta')
    WHERE e.id_veterinaria = ?
    GROUP BY e.id
    ORDER BY e.nombre_mascota ASC
");
$stmt->execute([$id_vet]);
$expedientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Mascotas de evaluaciones SIN expediente
$stmt2 = $pdo->prepare("
    SELECT DISTINCT ev.nombre_mascota, ev.nombre_dueno, ev.especie,
                    ev.telefono, ev.raza, ev.edad, ev.sexo,
                    ev.peso, ev.id_veterinaria,
                    COUNT(ev.id) AS total_visitas,
                    MAX(ev.fecha) AS ultima_visita
    FROM evaluaciones ev
    WHERE ev.id_veterinaria = ?
      AND ev.nombre_mascota IS NOT NULL AND ev.nombre_mascota <> ''
      AND NOT EXISTS (
          SELECT 1 FROM expedientes exp
          WHERE exp.nombre_mascota = ev.nombre_mascota
            AND exp.nombre_dueno   = ev.nombre_dueno
            AND exp.id_veterinaria = ev.id_veterinaria
      )
    GROUP BY ev.nombre_mascota, ev.nombre_dueno, ev.especie,
             ev.telefono, ev.raza, ev.edad, ev.sexo,
             ev.peso, ev.id_veterinaria
");
$stmt2->execute([$id_vet]);
$sinExpediente = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Mascotas de CITAS SIN expediente ni evaluación
// En cuanto el cliente agenda una cita aparece aquí con la etiqueta "Sin expediente"
$stmt3 = $pdo->prepare("
    SELECT
        CONVERT(c.nombre_mascota USING utf8mb4) COLLATE utf8mb4_spanish_ci AS nombre_mascota,
        CONVERT(c.nombre_dueno   USING utf8mb4) COLLATE utf8mb4_spanish_ci AS nombre_dueno,
        CONVERT(c.especie        USING utf8mb4) COLLATE utf8mb4_spanish_ci AS especie,
        CONVERT(c.telefono       USING utf8mb4) COLLATE utf8mb4_spanish_ci AS telefono,
        '' AS raza, '' AS edad, '' AS sexo, NULL AS peso,
        c.id_veterinaria,
        COUNT(DISTINCT c.id) AS total_visitas,
        MAX(c.fecha)         AS ultima_visita
    FROM citas c
    WHERE c.id_veterinaria = ?
      AND c.nombre_mascota IS NOT NULL
      AND CONVERT(c.nombre_mascota USING utf8mb4) <> ''
      AND NOT EXISTS (
          SELECT 1 FROM expedientes exp
          WHERE exp.nombre_mascota = CONVERT(c.nombre_mascota USING utf8mb4) COLLATE utf8mb4_spanish_ci
            AND exp.nombre_dueno   = CONVERT(c.nombre_dueno   USING utf8mb4) COLLATE utf8mb4_spanish_ci
            AND exp.id_veterinaria = c.id_veterinaria
      )
      AND NOT EXISTS (
          SELECT 1 FROM evaluaciones ev
          WHERE ev.nombre_mascota = CONVERT(c.nombre_mascota USING utf8mb4) COLLATE utf8mb4_spanish_ci
            AND ev.nombre_dueno   = CONVERT(c.nombre_dueno   USING utf8mb4) COLLATE utf8mb4_spanish_ci
            AND ev.id_veterinaria = c.id_veterinaria
      )
    GROUP BY c.nombre_mascota, c.nombre_dueno, c.especie,
             c.telefono, c.id_veterinaria
");
$stmt3->execute([$id_vet]);
$deCitas = $stmt3->fetchAll(PDO::FETCH_ASSOC);

// Marcar todos los sin-expediente con id=0 y campos vacíos
foreach ($sinExpediente as &$row) {
    $row['id']              = 0;
    $row['caracteristicas'] = '';
    $row['condiciones']     = '';
    $row['notas_clinicas']  = '';
}
foreach ($deCitas as &$row) {
    $row['id']              = 0;
    $row['caracteristicas'] = '';
    $row['condiciones']     = '';
    $row['notas_clinicas']  = '';
}
unset($row);

// Unir todo y ordenar alfabéticamente
$todosExpedientes = array_merge($expedientes, $sinExpediente, $deCitas);
usort($todosExpedientes, fn($a,$b) => strcmp($a['nombre_mascota'], $b['nombre_mascota']));

//Nombre del establecimiento 
$stmtVet = $pdo->prepare("SELECT establecimiento FROM veterinarios WHERE id=?");
$stmtVet->execute([$id_vet]);
$vetRow = $stmtVet->fetch(PDO::FETCH_ASSOC);
$nombre_vet_estab = $vetRow['establecimiento'] ?? 'Mi veterinaria';

$avColors = ['av-pink','av-blue','av-green','av-amber','av-purple'];
function avColor($i) { global $avColors; return $avColors[$i % count($avColors)]; }
function initials($name) {
    $words = explode(' ', trim($name));
    $ini = '';
    foreach (array_slice($words, 0, 2) as $w) $ini .= strtoupper(mb_substr($w, 0, 1));
    return $ini ?: '??';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="Expedientes.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@300..700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/Pet_Point.png" type="imagen/png">
<title>Expedientes</title>
</head>
<body>
<div class="app">

<!-- ===================== PANTALLA: LISTA ===================== -->
<div class="screen active" id="s-list">
    <h1>Expedientes</h1>
    <div class="btn-crearex">
        <div style="flex:1"></div>
        <button class="btn-crear" onclick="nuevoExpediente()">+  Crear nuevo expediente</button>
    </div>
    <div class="topbar">
        <div style="flex:1"></div>
        <span class="num-pacientes" id="count-label">
            Total de pacientes: <?= count($todosExpedientes) ?>
        </span>
    </div>
    <div class="search-wrap">
        <input class="search" placeholder="Buscar por nombre o dueño..." oninput="filterPets(this.value)">
    </div>

    <div id="pet-list">
    <?php if (empty($todosExpedientes)): ?>
        <div class="empty-state">😿 Aún no hay expedientes registrados.</div>
    <?php else: ?>
        <?php foreach ($todosExpedientes as $i => $exp):
            $visitas   = (int)$exp['total_visitas'];
            $ultima    = $exp['ultima_visita'] ? fechaCorta($exp['ultima_visita']) : '—';
            $ini       = initials($exp['nombre_mascota']);
            $avCls     = avColor($i);
            $conds_arr = array_filter(array_map('trim', explode(',', $exp['condiciones'] ?? '')));
            $emoji     = emojiEspecie($exp['especie'] ?? '');
            $esSinExp  = ($exp['id'] === 0);
        ?>
        <div class="pet-card"
             data-nombre="<?= htmlspecialchars(mb_strtolower($exp['nombre_mascota'])) ?>"
             data-dueno="<?= htmlspecialchars(mb_strtolower($exp['nombre_dueno'])) ?>"
             onclick="openPet('<?= htmlspecialchars(addslashes($exp['nombre_mascota'])) ?>','<?= htmlspecialchars(addslashes($exp['nombre_dueno'])) ?>')">
            <div class="avatar <?= $avCls ?>"><?= htmlspecialchars($ini) ?></div>
            <div class="pet-info">
                <div class="pet-name">
                    <?= htmlspecialchars($exp['nombre_mascota']) ?>
                    <?php if ($esSinExp): ?>
                        <span style="font-size:.72rem;background:#fff5e0;color:#d48a00;
                              padding:2px 8px;border-radius:10px;font-weight:700;margin-left:6px">
                            Sin expediente
                        </span>
                    <?php endif; ?>
                </div>
                <div class="pet-meta">
                    <?= $emoji ?> <?= htmlspecialchars($exp['especie'] ?: '—') ?> ·
                    <?= htmlspecialchars($exp['raza']  ?: '—') ?> ·
                    <?= htmlspecialchars($exp['edad']  ?: '—') ?> ·
                    <?= htmlspecialchars($exp['nombre_dueno']) ?>
                </div>
                <div class="pet-badges">
                    <?php foreach (array_slice($conds_arr, 0, 2) as $c): ?>
                        <span class="badge bp"><?= htmlspecialchars($c) ?></span>
                    <?php endforeach; ?>
                    <?php if ($exp['notas_clinicas']): ?>
                        <span class="badge bb">Con notas</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pet-right">
                <div class="pet-date">Última visita: <?= $ultima ?></div>
                <div class="pet-visits"><?= $visitas ?> cita<?= $visitas !== 1 ? 's' : '' ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <div class="empty-state" id="empty-state" style="display:none">
        No se encontraron pacientes con ese nombre.
    </div>
</div>

<!-- ===================== PANTALLA: DETALLE ===================== -->
<div class="screen" id="s-detail">
    <button class="btn-back" onclick="go('s-list')">< Expedientes</button>
    <h1 class="nom-mas" id="d-title">—</h1>
    <div class="topbar">
        <div style="flex:1"></div>
        <button class="btn-exped" style="margin-right:6px" onclick="go('s-add-hist')">
            + Agregar registro
        </button>
        <button class="btn-exped" id="btn-abrir-edicion">Editar expediente</button>
    </div>

    <div class="stat-grid">
        <div class="stat"><div class="stat-n" id="d-visits">0</div><div class="stat-l">Visitas totales</div></div>
        <div class="stat"><div class="stat-n" id="d-species">—</div><div class="stat-l">Especie</div></div>
        <div class="stat"><div class="stat-n" id="d-age">—</div><div class="stat-l">Edad</div></div>
        <div class="stat"><div class="stat-n" id="d-sexo-stat">—</div><div class="stat-l">Sexo</div></div>
        <div class="stat"><div class="stat-n" id="d-weight">—</div><div class="stat-l">Peso registrado</div></div>
    </div>

    <div class="sep">Datos generales</div>
    <div class="row2" style="margin-bottom:16px">
        <div>
            <div class="lbl">Dueño</div><div class="info-val" id="d-owner">—</div>
            <div class="spacer"></div>
            <div class="lbl">Teléfono</div><div class="info-val" id="d-telefono">—</div>
        </div>
        <div>
            <div class="lbl">Raza</div><div class="info-val" id="d-breed">—</div>
            <div class="spacer"></div>
            <div class="lbl">Señas particulares</div><div class="info-val" id="d-marks">—</div>
        </div>
    </div>

    <div class="sep">Condiciones conocidas</div>
    <div class="info-val" id="d-conditions" style="margin-bottom:16px">—</div>

    <div class="sep">Notas clínicas</div>
    <div class="info-val" id="d-notes" style="margin-bottom:16px;line-height:1.6">—</div>

    <div class="sep">Historial de servicios</div>
    <div class="di-hist" id="d-hist">
        <div class="empty-state">Cargando historial…</div>
    </div>
</div>

<!-- ===================== PANTALLA: EDITAR / NUEVO ===================== -->
<div class="screen" id="s-edit">
    <div class="topbar">
        <button class="btn-back" id="edit-back-btn" onclick="cancelarEdicion()"> < Ver expedientes</button>
        <h2 id="edit-titulo">Editar expediente</h2>
    </div>
    <div class="sub" id="edit-sub">Modifica los datos del expediente clínico</div>
    <div id="edit-ok"  class="ok-bar"><p>✓ Cambios guardados correctamente</p></div>
    <div id="edit-err" class="err-bar"><p>✗ Error al guardar. Intenta de nuevo.</p></div>

    <input type="hidden" id="e-id" value="0">

    <div class="sep">Datos generales de la mascota</div>
    <div class="row2">
        <div class="f"><label class="lbl">Nombre de la mascota </label><input id="e-name" placeholder="Ej. Mazapán"></div>
        <div class="f"><label class="lbl">Especie</label>
            <select id="e-species">
                <option value="">Seleccionar…</option>
                <option>Perro</option><option>Gato</option><option>Conejo</option>
                <option>Ave</option><option>Reptil</option><option>Otro</option>
            </select>
        </div>
    </div>
    <div class="row3">
        <div class="f"><label class="lbl">Raza</label><input id="e-breed" placeholder="Ej. Criollo"></div>
        <div class="f"><label class="lbl">Edad</label><input id="e-age" placeholder="Ej. 2 años"></div>
        <div class="f"><label class="lbl">Peso (kg)</label><input id="e-weight" type="number" step="0.1" placeholder="0.0"></div>
    </div>
    <div class="row2">
        <div class="f"><label class="lbl">Sexo</label>
            <select id="e-sexo">
                <option value="">Seleccionar…</option>
                <option>Macho</option><option>Hembra</option>
            </select>
        </div>
        <div class="f"><label class="lbl">Señas particulares / color</label><input id="e-marks" placeholder="Ej. Naranja con blanco"></div>
    </div>
    <div class="row2">
        <div class="f"><label class="lbl">Nombre del dueño </label><input id="e-owner" placeholder="Nombre completo"></div>
        <div class="f"><label class="lbl">Teléfono de contacto</label><input readonly id="e-phone" placeholder="Ej. 392 100 0000"></div>
    </div>

    <div class="sep">Condiciones o padecimientos conocidos</div>
    <div class="tag-wrap" id="cond-tags">
        <button class="tag" onclick="this.classList.toggle('on')">Alergia alimentaria</button>
        <button class="tag" onclick="this.classList.toggle('on')">Nerviosismo / ansiedad</button>
        <button class="tag" onclick="this.classList.toggle('on')">Diabetes</button>
        <button class="tag" onclick="this.classList.toggle('on')">Problemas cardiacos</button>
        <button class="tag" onclick="this.classList.toggle('on')">Problemas renales</button>
        <button class="tag" onclick="this.classList.toggle('on')">Epilepsia</button>
        <button class="tag" onclick="this.classList.toggle('on')">Hipotiroidismo</button>
        <button class="tag" onclick="this.classList.toggle('on')">Parásitos recurrentes</button>
        <button class="tag" onclick="this.classList.toggle('on')">Ninguno</button>
    </div>

    <div class="f"><label class="lbl">Notas clínicas generales</label>
        <textarea id="e-notes" placeholder="Observaciones generales, alergias medicamentosas, etc."></textarea>
    </div>

    <div id="edit-hist-section" style="display:none">
        <div class="sep">Registros del historial</div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th style="width:20%">Fecha</th>
                    <th style="width:28%">Servicio</th>
                    <th style="width:34%">Notas</th>
                    <th style="width:18%">Acción</th>
                </tr></thead>
                <tbody id="edit-hist-rows">
                    <tr><td colspan="4" style="text-align:center;color:#9a7a8a">—</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px">
        <button class="btn-agex" onclick="cancelarEdicion()">Cancelar</button>
        <button class="btn-agex" onclick="saveEdit()">Guardar cambios</button>
    </div>
</div>

<!-- ===================== PANTALLA: AGREGAR REGISTRO ===================== -->
<div class="screen" id="s-add-hist">
    <div class="topbar">
        <button class="btn-back" onclick="go('s-detail')"> < Ver expediente</button>
        <h2>Agregar registro al historial</h2>
    </div>
    <div class="sub" id="add-sub">Nuevo servicio proporcionado</div>
    <div id="add-ok"  class="ok-bar"><p>✓ Registro agregado al historial</p></div>
    <div id="add-err" class="err-bar"><p>✗ Error al guardar. Intenta de nuevo.</p></div>

    <div class="sep">Datos del servicio</div>
    <div class="row2">
        <div class="f"><label class="lbl">Fecha</label><input id="h-date" type="date" value="<?= date('Y-m-d') ?>"></div>
        <div class="f"><label class="lbl">Hora</label><input type="time" id="h-time" value="<?= date('H:i') ?>"></div>
    </div>
    <div class="f"><label class="lbl">Servicio proporcionado</label>
        <select id="h-service">
            <option value="">Seleccionar…</option>
            <option>Chequeo general</option><option>Vacunación</option>
            <option>Desparasitación</option><option>Cirugía</option>
            <option>Urgencia</option><option>Grooming / Estética</option>
            <option>Pet sitting</option><option>Otro</option>
        </select>
    </div>
    <div class="row2">
        <div class="f"><label class="lbl">Peso al ingreso (kg)</label><input id="h-weight" type="number" step="0.1" placeholder="0.0"></div>
        <div class="f"><label class="lbl">Establecimiento</label><input readonly id="h-estab" value="<?= htmlspecialchars($nombre_vet_estab) ?>"></div>
    </div>

    <div class="sep">Condición de ingreso</div>
    <div class="tag-wrap" id="cond-ingreso">
        <button class="tag" onclick="this.classList.toggle('on')">Sano / sin anomalías</button>
        <button class="tag" onclick="this.classList.toggle('on')">Nervioso</button>
        <button class="tag" onclick="this.classList.toggle('on')">Herida abierta</button>
        <button class="tag" onclick="this.classList.toggle('on')">Cortada</button>
        <button class="tag" onclick="this.classList.toggle('on')">Moretones / golpes</button>
        <button class="tag" onclick="this.classList.toggle('on')">Letárgico</button>
        <button class="tag" onclick="this.classList.toggle('on')">Fiebre</button>
        <button class="tag" onclick="this.classList.toggle('on')">Vómito reciente</button>
        <button class="tag" onclick="this.classList.toggle('on')">Sangrado</button>
        <button class="tag" onclick="this.classList.toggle('on')">Convulsiones</button>
        <button class="tag" onclick="this.classList.toggle('on')">Dificultad al respirar</button>
    </div>

    <div class="f"><label class="lbl">Notas clínicas del servicio</label>
        <textarea id="h-notes" placeholder="Observaciones, medicamentos aplicados, resultados, indicaciones al dueño, etc."></textarea>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px">
        <button class="btn-agex" onclick="go('s-detail')">Cancelar</button>
        <button class="btn-agex" onclick="saveHist()">Agregar al historial</button>
    </div>
</div>

</div><!-- /app -->

<!-- BTN REGRESO -->
<div class="btn-s">
    <a href="../ServiciosAdmin.html" class="btn-servicio">
    <img alt="Regresar servios" src="../img/Registro_Regreso.png">
    </a>
</div>

<!-- ===================== DATOS PHP → JS ===================== -->
<script>
const EXPS = {};
<?php foreach ($todosExpedientes as $i => $exp): ?>
EXPS[<?= json_encode($exp['nombre_mascota'].'|'.$exp['nombre_dueno']) ?>] = {
  id:          <?= (int)$exp['id'] ?>,
  nombre:      <?= json_encode($exp['nombre_mascota']) ?>,
  dueno:       <?= json_encode($exp['nombre_dueno']) ?>,
  especie:     <?= json_encode($exp['especie']         ?? '') ?>,
  telefono:    <?= json_encode($exp['telefono']        ?? '') ?>,
  raza:        <?= json_encode($exp['raza']            ?? '') ?>,
  edad:        <?= json_encode($exp['edad']            ?? '') ?>,
  peso:        <?= json_encode($exp['peso'] ? number_format((float)$exp['peso'],1) : '') ?>,
  sexo:        <?= json_encode($exp['sexo']            ?? '') ?>,
  marcas:      <?= json_encode($exp['caracteristicas'] ?? '') ?>,
  condiciones: <?= json_encode($exp['condiciones']     ?? '') ?>,
  notas:       <?= json_encode($exp['notas_clinicas']  ?? '') ?>,
  visitas:     <?= (int)$exp['total_visitas'] ?>,
  avCls:       <?= json_encode(avColor($i)) ?>
};
<?php endforeach; ?>

let curKey = null;

function go(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function emojiEspecie(esp) {
  const e = (esp || '').toLowerCase();
  if (e.includes('perro')) return '🐶';
  if (e.includes('gato'))  return '🐱';
  if (e.includes('conejo'))return '🐰';
  if (e.includes('ave') || e.includes('pájaro')) return '🐦';
  if (e.includes('reptil'))return '🦎';
  return '🐾';
}

function openPet(nombre, dueno) {
  const key = nombre + '|' + dueno;
  const p   = EXPS[key];
  if (!p) return;
  curKey = key;

  document.getElementById('d-title').textContent      = p.nombre;
  document.getElementById('d-visits').textContent     = p.visitas;
  const emoji = emojiEspecie(p.especie);
  document.getElementById('d-species').textContent    = p.especie ? emoji + ' ' + p.especie : '—';
  document.getElementById('d-age').textContent        = p.edad     || '—';
  document.getElementById('d-sexo-stat').textContent  = p.sexo     || '—';
  document.getElementById('d-weight').textContent     = p.peso     ? p.peso + ' kg' : '—';
  document.getElementById('d-owner').textContent      = p.dueno;
  document.getElementById('d-telefono').textContent   = p.telefono || '—';
  document.getElementById('d-breed').textContent      = p.raza     || '—';
  document.getElementById('d-marks').textContent      = p.marcas   || '—';
  document.getElementById('d-conditions').textContent = p.condiciones || 'Ninguna registrada';
  document.getElementById('d-notes').textContent      = p.notas    || 'Sin notas clínicas.';
  document.getElementById('add-sub').textContent      = p.nombre   + ' · Nuevo servicio proporcionado';

  cargarHistorial(p.nombre);
  go('s-detail');
}

async function cargarHistorial(nombre_mascota) {
  const cont = document.getElementById('d-hist');
  cont.innerHTML = '<div class="empty-state">Cargando…</div>';
  try {
    const fd = new FormData();
    fd.append('accion', 'listar_historial');
    fd.append('nombre_mascota', nombre_mascota);
    const resp = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok && data.rows && data.rows.length > 0) {
      cont.innerHTML = data.rows.map(r => {
        const condicion = r.condiciones ? 'Condición: ' + r.condiciones + '.' : '';
        const peso      = r.peso        ? ' Peso: ' + r.peso + ' kg.'        : '';
        const nota      = condicion || peso ? (condicion + peso).trim() : '';
        return `
        <div class="hist-row">
          <div class="hist-dot"></div>
          <div class="hist-body">
            <div class="hist-date">
              ${r.fecha_fmt}${r.hora ? ' · ' + r.hora : ''}
              ${r.nombre_veterinaria ? ' · ' + r.nombre_veterinaria : ''}
            </div>
            <div class="hist-text"><span class="badge ${r.badge}">${r.servicio}</span></div>
            ${nota ? `<div class="hist-note">${nota}</div>` : ''}
          </div>
        </div>`;
      }).join('');
    } else {
      cont.innerHTML = '<div class="empty-state">Sin registros de servicios aún.</div>';
    }
  } catch(e) {
    cont.innerHTML = '<div class="empty-state">Error al cargar historial.</div>';
  }
}

document.getElementById('btn-abrir-edicion').addEventListener('click', () => {
  if (!curKey) return;
  const p = EXPS[curKey];
  document.getElementById('e-id').value     = p.id;
  document.getElementById('e-name').value   = p.nombre;
  document.getElementById('e-breed').value  = p.raza;
  document.getElementById('e-age').value    = p.edad;
  document.getElementById('e-weight').value = p.peso;
  document.getElementById('e-owner').value  = p.dueno;
  document.getElementById('e-phone').value  = p.telefono;
  document.getElementById('e-marks').value  = p.marcas;
  document.getElementById('e-notes').value  = p.notas;
  setSelectVal('e-sexo',    p.sexo);
  setSelectVal('e-species', p.especie);
  const conds = (p.condiciones || '').split(',').map(s => s.trim().toLowerCase());
  document.querySelectorAll('#cond-tags .tag').forEach(t => {
    t.classList.toggle('on', conds.includes(t.textContent.trim().toLowerCase()));
  });
  document.getElementById('edit-titulo').textContent   = 'Editar expediente';
  document.getElementById('edit-sub').textContent      = p.nombre + ' · Modifica los datos';
  document.getElementById('edit-back-btn').textContent = '< Ver expediente';
  document.getElementById('edit-hist-section').style.display = 'block';
  hideBar('edit-ok'); hideBar('edit-err');
  cargarHistorialTabla(p.nombre);
  go('s-edit');
});

function nuevoExpediente() {
  curKey = null;
  ['e-name','e-breed','e-age','e-weight','e-owner','e-phone','e-marks','e-notes']
    .forEach(id => { document.getElementById(id).value = ''; });
  document.getElementById('e-id').value              = '0';
  document.getElementById('e-sexo').selectedIndex    = 0;
  document.getElementById('e-species').selectedIndex = 0;
  document.querySelectorAll('#cond-tags .tag').forEach(t => t.classList.remove('on'));
  document.getElementById('edit-titulo').textContent    = 'Nuevo expediente';
  document.getElementById('edit-sub').textContent       = 'Registra un nuevo paciente';
  document.getElementById('edit-back-btn').textContent  = '< Expedientes';
  document.getElementById('edit-hist-section').style.display = 'none';
  hideBar('edit-ok'); hideBar('edit-err');
  go('s-edit');
}

function cancelarEdicion() {
  curKey ? go('s-detail') : go('s-list');
}

async function cargarHistorialTabla(nombre_mascota) {
  const tbody = document.getElementById('edit-hist-rows');
  tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#9a7a8a">Cargando…</td></tr>';
  try {
    const fd = new FormData();
    fd.append('accion', 'listar_historial');
    fd.append('nombre_mascota', nombre_mascota);
    const resp = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok && data.rows && data.rows.length > 0) {
      tbody.innerHTML = data.rows.map(r => `
        <tr>
          <td>${r.fecha_fmt}</td>
          <td><span class="badge ${r.badge}">${r.servicio}</span></td>
          <td>${r.notas_clinicas || '—'}</td>
          <td>${r.origen === 'historial'
            ? `<button class="btn-sm btn-del" onclick="delRowTabla(this,${r.id})">✕ Eliminar</button>`
            : '<span style="font-size:.78rem;color:#9a7a8a">De evaluación</span>'
          }</td>
        </tr>`).join('');
    } else {
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#9a7a8a">Sin registros</td></tr>';
    }
  } catch(e) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#e24b4a">Error al cargar</td></tr>';
  }
}

async function saveEdit() {
  const nombre = document.getElementById('e-name').value.trim();
  const dueno  = document.getElementById('e-owner').value.trim();
  if (!nombre || !dueno) { alert('El nombre de la mascota y el dueño son obligatorios.'); return; }
  const conds = [...document.querySelectorAll('#cond-tags .tag.on')]
                  .map(t => t.textContent.trim()).join(', ') || 'Ninguno';
  const fd = new FormData();
  fd.append('accion',          'guardar_expediente');
  fd.append('id',              document.getElementById('e-id').value);
  fd.append('nombre_mascota',  nombre);
  fd.append('nombre_dueno',    dueno);
  fd.append('especie',         document.getElementById('e-species').value);
  fd.append('telefono',        document.getElementById('e-phone').value.trim());
  fd.append('raza',            document.getElementById('e-breed').value.trim());
  fd.append('edad',            document.getElementById('e-age').value.trim());
  fd.append('peso',            document.getElementById('e-weight').value);
  fd.append('sexo',            document.getElementById('e-sexo').value);
  fd.append('caracteristicas', document.getElementById('e-marks').value.trim());
  fd.append('condiciones',     conds);
  fd.append('notas_clinicas',  document.getElementById('e-notes').value.trim());
  try {
    const resp = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) {
      const newId  = parseInt(data.msg);
      const newKey = nombre + '|' + dueno;
      EXPS[newKey] = {
        id: newId, nombre, dueno,
        especie: fd.get('especie'), telefono: fd.get('telefono'),
        raza: fd.get('raza'), edad: fd.get('edad'), peso: fd.get('peso'),
        sexo: fd.get('sexo'), marcas: fd.get('caracteristicas'),
        condiciones: fd.get('condiciones'), notas: fd.get('notas_clinicas'),
        visitas: EXPS[newKey]?.visitas ?? 0, avCls: 'av-pink'
      };
      curKey = newKey;
      showBar('edit-ok');
      setTimeout(() => openPet(nombre, dueno), 1800);
    } else { showBar('edit-err'); }
  } catch(e) { showBar('edit-err'); }
}

async function saveHist() {
  const p = curKey ? EXPS[curKey] : null;
  if (!p) return;
  if (!document.getElementById('h-service').value) {
    alert('Selecciona el servicio proporcionado.'); return;
  }
  const conds = [...document.querySelectorAll('#cond-ingreso .tag.on')]
                  .map(t => t.textContent.trim()).join(', ');
  const fd = new FormData();
  fd.append('accion',         'agregar_historial');
  fd.append('nombre_mascota', p.nombre);
  fd.append('nombre_dueno',   p.dueno);
  fd.append('especie',        p.especie  || '');
  fd.append('raza',           p.raza     || '');
  fd.append('edad',           p.edad     || '');
  fd.append('telefono',       p.telefono || '');
  fd.append('peso',           document.getElementById('h-weight').value);
  fd.append('servicio',       document.getElementById('h-service').value);
  fd.append('fecha',          document.getElementById('h-date').value);
  fd.append('hora',           document.getElementById('h-time').value + ':00');
  fd.append('condiciones',    conds);
  fd.append('notas',          document.getElementById('h-notes').value.trim());
  fd.append('nombre_vet',     document.getElementById('h-estab').value.trim());
  try {
    const resp = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) {
      if (EXPS[curKey]) EXPS[curKey].visitas++;
      document.getElementById('h-notes').value    = '';
      document.getElementById('h-weight').value   = '';
      document.getElementById('h-service').selectedIndex = 0;
      document.querySelectorAll('#cond-ingreso .tag.on').forEach(t => t.classList.remove('on'));
      showBar('add-ok');
      setTimeout(() => openPet(p.nombre, p.dueno), 1800);
    } else { showBar('add-err'); }
  } catch(e) { showBar('add-err'); }
}

async function delRowTabla(btn, id) {
  if (!confirm('¿Eliminar este registro del historial?')) return;
  const fd = new FormData();
  fd.append('accion', 'eliminar_historial');
  fd.append('id', id);
  const resp = await fetch(window.location.href, { method: 'POST', body: fd });
  const data = await resp.json();
  if (data.ok) btn.closest('tr').remove();
}

function filterPets(q) {
  const ql = q.toLowerCase();
  const cards = document.querySelectorAll('#pet-list .pet-card');
  let vis = 0;
  cards.forEach(c => {
    const show = !ql || c.dataset.nombre.includes(ql) || c.dataset.dueno.includes(ql);
    c.style.display = show ? 'flex' : 'none';
    if (show) vis++;
  });
  document.getElementById('count-label').textContent = 'Total de pacientes: ' + vis;
  document.getElementById('empty-state').style.display = vis === 0 ? 'block' : 'none';
}

function setSelectVal(id, val) {
  const s = document.getElementById(id);
  for (const o of s.options) o.selected = (o.value === val);
}
function showBar(id) {
  const el = document.getElementById(id);
  el.style.display = 'block';
  setTimeout(() => el.style.display = 'none', 2500);
}
function hideBar(id) { document.getElementById(id).style.display = 'none'; }
</script>
</body>
</html>