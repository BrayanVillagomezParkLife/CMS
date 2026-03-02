<?php
declare(strict_types=1);

/**
 * ============================================================
 * PARK LIFE PROPERTIES — db.php
 * Conexión PDO MySQL — Patrón Singleton
 * ============================================================
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    /**
     * Retorna la instancia única de PDO.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }
        return self::$instance;
    }

    /**
     * Crea la conexión PDO con opciones de seguridad y rendimiento.
     */
    private static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        // MYSQL_ATTR_INIT_COMMAND solo existe si el driver pdo_mysql está activo
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            return $pdo;
        } catch (PDOException $e) {
            self::handleConnectionError($e);
        }
    }

    /**
     * Maneja errores de conexión de forma segura (sin exponer credenciales).
     */
    private static function handleConnectionError(PDOException $e): never
    {
        $log_msg = sprintf(
            "[%s] DB Connection Error: %s\n",
            date('Y-m-d H:i:s'),
            $e->getMessage()
        );
        error_log($log_msg);

        if (APP_DEBUG) {
            throw new RuntimeException('Error de conexión a la base de datos: ' . $e->getMessage());
        }

        // En producción, responder según el tipo de request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            http_response_code(503);
            echo json_encode(['success' => false, 'error' => 'Servicio no disponible']);
        } else {
            http_response_code(503);
            include BASE_PATH . '/pages/error.php';
        }
        exit;
    }

    // Prevenir clonación e instanciación directa
    private function __construct() {}
    private function __clone() {}
}

/**
 * Helper global — shortcut para obtener la conexión PDO.
 * Uso: $pdo = db();
 */
function db(): PDO
{
    return Database::getInstance();
}

/**
 * ============================================================
 * HELPERS DE QUERY
 * Wrappers para las operaciones más comunes con PDO
 * ============================================================
 */

/**
 * Ejecuta una query y retorna todos los resultados.
 *
 * @param string $sql    Query con placeholders (:param o ?)
 * @param array  $params Parámetros a bindear
 * @return array
 */
function dbFetchAll(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Ejecuta una query y retorna una sola fila.
 *
 * @param string $sql
 * @param array  $params
 * @return array|null
 */
function dbFetchOne(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Ejecuta una query y retorna el valor de la primera columna de la primera fila.
 *
 * @param string $sql
 * @param array  $params
 * @return mixed
 */
function dbFetchValue(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Ejecuta INSERT, UPDATE o DELETE. Retorna número de filas afectadas.
 *
 * @param string $sql
 * @param array  $params
 * @return int
 */
function dbExecute(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Inserta un registro y retorna el último ID insertado.
 *
 * @param string $sql
 * @param array  $params
 * @return int
 */
function dbInsert(string $tableOrSql, array $params = []): int
{
    // Si el primer argumento es un nombre de tabla (sin espacios), construir el INSERT
    if (!str_contains($tableOrSql, ' ')) {
        $table = $tableOrSql;
        $cols  = implode(', ', array_keys($params));
        $phs   = implode(', ', array_fill(0, count($params), '?'));
        $sql   = "INSERT INTO `$table` ($cols) VALUES ($phs)";
        $vals  = array_values($params);
    } else {
        $sql  = $tableOrSql;
        $vals = $params;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($vals);
    return (int) db()->lastInsertId();
}

/**
 * ============================================================
 * CACHE DE QUERIES (JSON en disco)
 * TTL configurable, invalidación manual desde admin
 * ============================================================
 */

/**
 * Obtiene datos del cache o ejecuta el callback y los guarda.
 *
 * @param string   $key      Identificador único del cache
 * @param callable $callback Función que retorna los datos si no hay cache
 * @param int      $ttl      Tiempo de vida en segundos (default: CACHE_TTL)
 * @return mixed
 */
function dbCache(string $key, callable $callback, int $ttl = CACHE_TTL): mixed
{
    if (!CACHE_ACTIVE) {
        return $callback();
    }

    $file = CACHE_PATH . '/' . preg_replace('/[^a-z0-9_-]/', '_', $key) . '.json';

    // ¿El cache existe y no ha expirado?
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        $data = json_decode(file_get_contents($file), true);
        if ($data !== null) {
            return $data;
        }
    }

    // Ejecutar callback y guardar
    $data = $callback();
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $data;
}

/**
 * Invalida uno o todos los archivos de cache.
 *
 * @param string|null $key Si es null, borra todo el cache
 */
function dbCacheInvalidate(?string $key = null): void
{
    if ($key !== null) {
        $file = CACHE_PATH . '/' . preg_replace('/[^a-z0-9_-]/', '_', $key) . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
        return;
    }

    // Borrar todo el cache
    foreach (glob(CACHE_PATH . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
}