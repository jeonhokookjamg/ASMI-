<?php
/**
 * ASMI — Configuración de conexión a la base de datos (ronda 2).
 *
 * Los valores se toman de variables de entorno si existen; si no, se usan
 * los valores locales por defecto de XAMPP, documentados aquí SOLO como
 * ejemplo para desarrollo local (en XAMPP, el usuario "root" de MySQL no
 * tiene contraseña por defecto). No se inventó ninguna credencial nueva:
 * en un servidor real, define las variables de entorno ASMI_DB_HOST,
 * ASMI_DB_NAME, ASMI_DB_USER y ASMI_DB_PASS con los datos de tu servidor.
 */

if (!defined('ASMI_DB_HOST')) define('ASMI_DB_HOST', getenv('ASMI_DB_HOST') ?: '127.0.0.1');
if (!defined('ASMI_DB_NAME')) define('ASMI_DB_NAME', getenv('ASMI_DB_NAME') ?: 'asmi_medical');
if (!defined('ASMI_DB_USER')) define('ASMI_DB_USER', getenv('ASMI_DB_USER') ?: 'root');
if (!defined('ASMI_DB_PASS')) define('ASMI_DB_PASS', getenv('ASMI_DB_PASS') !== false ? getenv('ASMI_DB_PASS') : '');

/**
 * Devuelve una conexión PDO única (singleton) con prepared statements
 * reales (emulación desactivada) y errores como excepciones.
 */
function asmi_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . ASMI_DB_HOST . ';dbname=' . ASMI_DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, ASMI_DB_USER, ASMI_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
