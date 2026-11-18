<?php
/**
 * ASMI — API REST mínima (ronda 2).
 *
 * Endpoints (parámetro "action", por GET o POST):
 *   ping            → estado de la API y de la conexión a la BD
 *   login           → valida usuario/contraseña contra la tabla personal
 *                     y devuelve los datos del usuario SIN la contraseña
 *   buscarPaciente  → paciente por NSS EXACTO
 *   citas_listar    → citas agendadas de un paciente (nss)
 *   citas_crear     → crea una cita; rechaza si médico+fecha+hora está
 *                     ocupado (UNIQUE de la tabla citas) o si el paciente
 *                     ya tiene cita a esa hora
 *   citas_cancelar  → cancela la cita de un paciente (nss+medico+fecha+hora)
 *   directorio      → personal (sin contraseñas); filtros opcionales:
 *                     estado, hospital (clave), q (texto en nombre/área/rol)
 *   stats           → conteos generales de la red
 *   recuperar_solicitar → registra la solicitud de recuperación y devuelve
 *                     los correos destino ENMASCARADOS (institucional y personal)
 *   recuperar_aprobar   → solo un ejecutivo (ASMI_EJECUTIVOS): genera la
 *                     contraseña temporal, la actualiza en la cuenta y la
 *                     envía con mail() a AMBOS correos; marca el envío
 *   credencial_verificar → datos públicos de una credencial por folio
 *   credencial_estado    → sincroniza (upsert) el registro de una credencial
 *
 * Todas las respuestas son JSON con la forma:
 *   éxito: {"ok":true, ...}
 *   error: {"ok":false, "error":"mensaje", "codigo":"CODIGO"}
 * Todas las consultas usan prepared statements (PDO).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

/** Los únicos 4 usuarios con control ejecutivo total del sistema. */
const ASMI_EJECUTIVOS = [
    'maldonado_jose_angel',
    'barrera_diego_esau',
    'luna_diego_antonio',
    'resendiz_abel_alejandro',
];

function asmi_ok(array $extra = []): void
{
    echo json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function asmi_error(string $mensaje, string $codigo = 'ERROR', int $http = 400): void
{
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $mensaje, 'codigo' => $codigo], JSON_UNESCAPED_UNICODE);
    exit;
}

function asmi_param(string $nombre): string
{
    $v = $_POST[$nombre] ?? $_GET[$nombre] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Quita la contraseña de una fila de personal antes de responder. */
function asmi_publico(array $fila): array
{
    unset($fila['password']);
    $fila['es_ejecutivo'] = in_array($fila['usuario'] ?? '', ASMI_EJECUTIVOS, true);
    return $fila;
}

/** Enmascara un correo para mostrarlo sin exponerlo: j***@dominio. */
function asmi_enmascarar(string $correo): ?string
{
    if ($correo === '' || strpos($correo, '@') === false) return null;
    [$local, $dom] = explode('@', $correo, 2);
    return substr($local, 0, 1) . '***@' . $dom;
}

/** Normaliza la hora de la BD (HH:MM:SS) a HH:MM. */
function asmi_hora_corta($hora): string
{
    return substr((string)$hora, 0, 5);
}

$action = asmi_param('action');
if ($action === '') {
    asmi_error('Falta el parámetro action', 'SIN_ACCION');
}

try {
    $db = asmi_db();

    switch ($action) {

        case 'ping':
            $db->query('SELECT 1');
            asmi_ok(['sistema' => 'ASMI', 'version' => '2.0', 'bd' => ASMI_DB_NAME]);
            break;

        case 'login': {
            $usuario  = asmi_param('usuario');
            $password = asmi_param('password');
            if ($usuario === '' || $password === '') {
                asmi_error('Usuario y contraseña son obligatorios', 'DATOS_INCOMPLETOS');
            }
            $st = $db->prepare('SELECT id, hospital_id, clave_hospital, hospital, estado, tipo, area, rol, especialidad, nombre, usuario, password, correo, correo_personal, consultorio, cedula
                                FROM personal WHERE usuario = :u LIMIT 1');
            $st->execute([':u' => $usuario]);
            $fila = $st->fetch();
            if (!$fila) {
                asmi_error('Credenciales incorrectas', 'LOGIN_INVALIDO', 401);
            }
            $guardada = (string)$fila['password'];
            // Compatibilidad: contraseñas heredadas en texto plano y, si en el
            // futuro se migran a password_hash(), también se aceptan.
            $valida = hash_equals($guardada, $password)
                   || (strpos($guardada, '$2y$') === 0 && password_verify($password, $guardada));
            if (!$valida) {
                asmi_error('Credenciales incorrectas', 'LOGIN_INVALIDO', 401);
            }
            asmi_ok(['usuario' => asmi_publico($fila)]);
            break;
        }

        case 'buscarPaciente': {
            $nss = asmi_param('nss');
            if ($nss === '') {
                asmi_error('El NSS es obligatorio', 'DATOS_INCOMPLETOS');
            }
            // Coincidencia EXACTA de NSS (nunca parcial).
            $st = $db->prepare('SELECT id, folio_ins, nss, nombre, hospital_clave, hospital, estado, cama, consultorio, especialidad, fecha_ingreso, medico_origen, medico_actual, tipo
                                FROM pacientes WHERE nss = :nss LIMIT 1');
            $st->execute([':nss' => $nss]);
            $pac = $st->fetch();
            if (!$pac) {
                asmi_error('Paciente no encontrado', 'NO_ENCONTRADO', 404);
            }
            asmi_ok(['paciente' => $pac]);
            break;
        }

        case 'citas_listar': {
            $nss = asmi_param('nss');
            if ($nss === '') {
                asmi_error('El NSS es obligatorio', 'DATOS_INCOMPLETOS');
            }
            $st = $db->prepare("SELECT id, paciente_nss, paciente_folio, medico_nombre, area, fecha, hora, hospital_clave, consultorio, estado
                                FROM citas WHERE paciente_nss = :nss AND estado = 'Agendada'
                                ORDER BY fecha, hora");
            $st->execute([':nss' => $nss]);
            $citas = [];
            foreach ($st->fetchAll() as $c) {
                $citas[] = [
                    'id'             => (int)$c['id'],
                    'nss'            => $c['paciente_nss'],
                    'paciente_folio' => $c['paciente_folio'],
                    'medico'         => $c['medico_nombre'],
                    'area'           => $c['area'],
                    'fecha'          => $c['fecha'],
                    'hora'           => asmi_hora_corta($c['hora']),
                    'hospital_clave' => $c['hospital_clave'],
                    'consultorio'    => $c['consultorio'],
                    'estado'         => $c['estado'],
                ];
            }
            asmi_ok(['citas' => $citas]);
            break;
        }

        case 'citas_crear': {
            $nss      = asmi_param('nss');
            $folio    = asmi_param('paciente_folio');
            $medico   = asmi_param('medico');
            $area     = asmi_param('area');
            $fecha    = asmi_param('fecha');
            $hora     = asmi_param('hora');
            $hosp     = asmi_param('hospital_clave');
            $consul   = asmi_param('consultorio');
            if ($nss === '' || $medico === '' || $fecha === '' || $hora === '') {
                asmi_error('Faltan datos obligatorios: nss, medico, fecha, hora', 'DATOS_INCOMPLETOS');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                asmi_error('La fecha debe tener formato AAAA-MM-DD', 'FECHA_INVALIDA');
            }
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora)) {
                asmi_error('La hora debe tener formato HH:MM', 'HORA_INVALIDA');
            }
            $horaBD = strlen($hora) === 5 ? $hora . ':00' : $hora;
            try {
                $st = $db->prepare("INSERT INTO citas
                    (paciente_nss, paciente_folio, medico_nombre, area, fecha, hora, hospital_clave, consultorio, estado)
                    VALUES (:nss, :folio, :medico, :area, :fecha, :hora, :hosp, :consul, 'Agendada')");
                $st->execute([
                    ':nss'    => $nss,
                    ':folio'  => $folio !== '' ? $folio : null,
                    ':medico' => $medico,
                    ':area'   => $area !== '' ? $area : null,
                    ':fecha'  => $fecha,
                    ':hora'   => $horaBD,
                    ':hosp'   => $hosp !== '' ? $hosp : null,
                    ':consul' => $consul !== '' ? $consul : null,
                ]);
            } catch (PDOException $e) {
                // 23000 = violación de UNIQUE (médico ocupado o paciente duplicado)
                if ($e->getCode() === '23000') {
                    $chk = $db->prepare("SELECT COUNT(*) FROM citas
                        WHERE medico_nombre = :m AND fecha = :f AND hora = :h AND estado <> 'Cancelada'");
                    $chk->execute([':m' => $medico, ':f' => $fecha, ':h' => $horaBD]);
                    if ((int)$chk->fetchColumn() > 0) {
                        asmi_error('El médico ya tiene una cita en esa fecha y hora', 'MEDICO_OCUPADO', 409);
                    }
                    asmi_error('El paciente ya tiene una cita en esa fecha y hora', 'PACIENTE_OCUPADO', 409);
                }
                throw $e;
            }
            asmi_ok(['id' => (int)$db->lastInsertId(), 'mensaje' => 'Cita creada']);
            break;
        }

        case 'citas_cancelar': {
            $nss    = asmi_param('nss');
            $medico = asmi_param('medico');
            $fecha  = asmi_param('fecha');
            $hora   = asmi_param('hora');
            $id     = asmi_param('id');
            if ($id !== '') {
                $st = $db->prepare("UPDATE citas SET estado = 'Cancelada'
                                    WHERE id = :id AND paciente_nss = :nss AND estado = 'Agendada'");
                $st->execute([':id' => (int)$id, ':nss' => $nss]);
            } else {
                if ($nss === '' || $medico === '' || $fecha === '' || $hora === '') {
                    asmi_error('Faltan datos: id, o bien nss, medico, fecha y hora', 'DATOS_INCOMPLETOS');
                }
                $horaBD = strlen($hora) === 5 ? $hora . ':00' : $hora;
                $st = $db->prepare("UPDATE citas SET estado = 'Cancelada'
                                    WHERE paciente_nss = :nss AND medico_nombre = :medico
                                      AND fecha = :fecha AND hora = :hora AND estado = 'Agendada'");
                $st->execute([':nss' => $nss, ':medico' => $medico, ':fecha' => $fecha, ':hora' => $horaBD]);
            }
            if ($st->rowCount() === 0) {
                asmi_error('No se encontró una cita agendada con esos datos', 'NO_ENCONTRADO', 404);
            }
            asmi_ok(['mensaje' => 'Cita cancelada']);
            break;
        }

        case 'directorio': {
            $sql = 'SELECT id, hospital_id, clave_hospital, hospital, estado, tipo, area, rol, especialidad, nombre, usuario, correo, correo_personal, consultorio, cedula
                    FROM personal WHERE 1=1';
            $par = [];
            $estado = asmi_param('estado');
            $hosp   = asmi_param('hospital');
            $q      = asmi_param('q');
            if ($estado !== '') { $sql .= ' AND estado = :estado'; $par[':estado'] = $estado; }
            if ($hosp !== '')   { $sql .= ' AND clave_hospital = :hosp'; $par[':hosp'] = $hosp; }
            if ($q !== '') {
                $sql .= ' AND (nombre LIKE :q OR area LIKE :q OR rol LIKE :q OR especialidad LIKE :q)';
                $par[':q'] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY estado, hospital, nombre LIMIT 2000';
            $st = $db->prepare($sql);
            $st->execute($par);
            $lista = [];
            foreach ($st->fetchAll() as $f) {
                $lista[] = asmi_publico($f);
            }
            asmi_ok(['total' => count($lista), 'personal' => $lista]);
            break;
        }

        case 'stats': {
            $contar = function (string $tabla) use ($db): int {
                $permitidas = ['hospitales', 'personal', 'pacientes', 'citas'];
                if (!in_array($tabla, $permitidas, true)) return 0;
                return (int)$db->query('SELECT COUNT(*) FROM ' . $tabla)->fetchColumn();
            };
            $citasHoy = (int)$db->query("SELECT COUNT(*) FROM citas WHERE fecha = CURDATE() AND estado = 'Agendada'")->fetchColumn();
            asmi_ok(['stats' => [
                'hospitales'      => $contar('hospitales'),
                'personal'        => $contar('personal'),
                'pacientes'       => $contar('pacientes'),
                'citas_total'     => $contar('citas'),
                'citas_hoy'       => $citasHoy,
                'citas_agendadas' => (int)$db->query("SELECT COUNT(*) FROM citas WHERE estado = 'Agendada'")->fetchColumn(),
            ]]);
            break;
        }

        case 'recuperar_solicitar': {
            $usuario = asmi_param('usuario');
            $idSol   = asmi_param('id');
            if ($usuario === '') {
                asmi_error('El usuario es obligatorio', 'DATOS_INCOMPLETOS');
            }
            $st = $db->prepare('SELECT nombre, correo, correo_personal FROM personal WHERE usuario = :u LIMIT 1');
            $st->execute([':u' => $usuario]);
            $fila = $st->fetch();
            if (!$fila) {
                asmi_error('Usuario no encontrado', 'NO_ENCONTRADO', 404);
            }
            if ($idSol === '') $idSol = 'REC-' . strtoupper(bin2hex(random_bytes(5)));
            $ins = $db->prepare("INSERT INTO solicitudes_recuperacion (id, usuario, estado, envio)
                                 VALUES (:id, :u, 'Pendiente', 'pendiente')
                                 ON DUPLICATE KEY UPDATE estado = 'Pendiente'");
            $ins->execute([':id' => $idSol, ':u' => $usuario]);
            asmi_ok([
                'id' => $idSol,
                'correo_institucional' => asmi_enmascarar((string)($fila['correo'] ?? '')),
                'correo_personal' => asmi_enmascarar((string)($fila['correo_personal'] ?? '')),
            ]);
            break;
        }

        case 'recuperar_aprobar': {
            $idSol       = asmi_param('id') !== '' ? asmi_param('id') : asmi_param('id_solicitud');
            $admin       = asmi_param('usuario_admin');
            $passAdmin   = asmi_param('password_admin');
            // Solo la administración (los 4 ejecutivos) puede aprobar.
            if (!in_array($admin, ASMI_EJECUTIVOS, true)) {
                asmi_error('Solo la administración puede aprobar recuperaciones', 'SIN_PERMISO', 403);
            }
            if ($passAdmin !== '') {
                $stA = $db->prepare('SELECT password FROM personal WHERE usuario = :u LIMIT 1');
                $stA->execute([':u' => $admin]);
                $filaA = $stA->fetch();
                if (!$filaA || !hash_equals((string)$filaA['password'], $passAdmin)) {
                    asmi_error('Credenciales de administrador inválidas', 'SIN_PERMISO', 403);
                }
            }
            $st = $db->prepare('SELECT * FROM solicitudes_recuperacion WHERE id = :id LIMIT 1');
            $st->execute([':id' => $idSol]);
            $sol = $st->fetch();
            if (!$sol) {
                asmi_error('Solicitud no encontrada', 'NO_ENCONTRADO', 404);
            }
            if ($sol['estado'] !== 'Pendiente') {
                asmi_error('La solicitud ya fue resuelta', 'YA_RESUELTA', 409);
            }
            $usuario = (string)$sol['usuario'];
            $stU = $db->prepare('SELECT nombre, correo, correo_personal FROM personal WHERE usuario = :u LIMIT 1');
            $stU->execute([':u' => $usuario]);
            $dest = $stU->fetch();
            if (!$dest) {
                asmi_error('Usuario no encontrado', 'NO_ENCONTRADO', 404);
            }
            // Contraseña temporal nueva: se actualiza la cuenta y se envía.
            $temporal = 'ASMI-' . strtoupper(bin2hex(random_bytes(4)));
            $up = $db->prepare('UPDATE personal SET password = :p WHERE usuario = :u');
            $up->execute([':p' => $temporal, ':u' => $usuario]);
            $asunto = 'ASMI — Tu contraseña temporal';
            $cuerpo = "Hola " . ($dest['nombre'] ?? '') . ":\n\n"
                    . "La administración de ASMI aprobó tu solicitud de recuperación.\n"
                    . "Tu contraseña temporal es: " . $temporal . "\n\n"
                    . "Ingresa con ella y el sistema te pedirá cambiarla de inmediato.\n"
                    . "Si no solicitaste este cambio, avisa a la administración de tu unidad.";
            $cab = 'From: no-responder@asmi.gob.mx' . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
            $enviados = 0; $destinos = 0;
            foreach ([(string)($dest['correo'] ?? ''), (string)($dest['correo_personal'] ?? '')] as $correo) {
                if ($correo !== '') { $destinos++; if (@mail($correo, $asunto, $cuerpo, $cab)) $enviados++; }
            }
            $envio = ($destinos > 0 && $enviados === $destinos) ? 'enviado' : 'pendiente';
            $upS = $db->prepare("UPDATE solicitudes_recuperacion
                                 SET estado = 'Aprobada', envio = :e, resuelta_por = :a, resuelta_en = NOW()
                                 WHERE id = :id");
            $upS->execute([':e' => $envio, ':a' => $admin, ':id' => $idSol]);
            asmi_ok(['id' => $idSol, 'temporal' => $temporal, 'envio' => $envio,
                     'correos_enviados' => $enviados, 'correos_destino' => $destinos]);
            break;
        }

        case 'credencial_verificar': {
            $folio = asmi_param('folio');
            if ($folio === '') {
                asmi_error('El folio es obligatorio', 'DATOS_INCOMPLETOS');
            }
            $st = $db->prepare('SELECT folio, usuario, version, emision, vencimiento, estado, folio_anterior
                                FROM credenciales WHERE folio = :f LIMIT 1');
            $st->execute([':f' => $folio]);
            $c = $st->fetch();
            if (!$c) {
                asmi_error('Folio no localizado', 'NO_ENCONTRADO', 404);
            }
            $nombre = null;
            $stN = $db->prepare('SELECT nombre, rol, hospital FROM personal WHERE usuario = :u LIMIT 1');
            $stN->execute([':u' => $c['usuario']]);
            if ($fN = $stN->fetch()) $nombre = $fN;
            asmi_ok(['credencial' => $c, 'titular' => $nombre]);
            break;
        }

        case 'credencial_estado': {
            $usuario     = asmi_param('usuario');
            $folio       = asmi_param('folio');
            $version     = (int)asmi_param('version');
            $emision     = asmi_param('emision');
            $vencimiento = asmi_param('vencimiento');
            $estado      = asmi_param('estado');
            if ($usuario === '') {
                asmi_error('El usuario es obligatorio', 'DATOS_INCOMPLETOS');
            }
            if ($folio !== '' && $emision !== '' && $vencimiento !== '') {
                if ($estado === '') {
                    $hoy = new DateTimeImmutable('today');
                    $ven = DateTimeImmutable::createFromFormat('Y-m-d', $vencimiento) ?: $hoy;
                    $estado = $ven < $hoy ? 'Vencida' : ($ven <= $hoy->modify('+30 days') ? 'Por vencer' : 'Vigente');
                }
                $prev = $db->prepare("UPDATE credenciales SET estado = 'Reemplazada'
                                      WHERE usuario = :u AND folio <> :f AND estado <> 'Reemplazada'");
                $prev->execute([':u' => $usuario, ':f' => $folio]);
                $up = $db->prepare("INSERT INTO credenciales (folio, usuario, version, emision, vencimiento, estado)
                                    VALUES (:f, :u, :v, :e, :ven, :est)
                                    ON DUPLICATE KEY UPDATE version = VALUES(version), emision = VALUES(emision),
                                      vencimiento = VALUES(vencimiento), estado = VALUES(estado)");
                $up->execute([':f' => $folio, ':u' => $usuario, ':v' => max(1, $version),
                              ':e' => $emision, ':ven' => $vencimiento, ':est' => $estado]);
            }
            $st = $db->prepare('SELECT folio, usuario, version, emision, vencimiento, estado, folio_anterior
                                FROM credenciales WHERE usuario = :u ORDER BY version DESC LIMIT 1');
            $st->execute([':u' => $usuario]);
            asmi_ok(['credencial' => $st->fetch() ?: null]);
            break;
        }

        default:
            asmi_error('Acción no válida: ' . $action, 'ACCION_INVALIDA');
    }
} catch (PDOException $e) {
    asmi_error('Error de base de datos', 'ERROR_BD', 500);
} catch (Throwable $e) {
    asmi_error('Error interno del servidor', 'ERROR_INTERNO', 500);
}
