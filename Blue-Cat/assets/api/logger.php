<?php
/**
 * Sistema de Logging Estructurado - Blue-Cat ERP
 * 
 * Niveles de log:
 * - DEBUG: Información detallada para debugging
 * - INFO: Eventos informativos
 * - WARNING: Advertencias que no impiden el funcionamiento
 * - ERROR: Errores que impiden una operación
 * - CRITICAL: Errores críticos del sistema
 */

class Logger {
    private static $instance = null;
    private $logPath;
    private $maxFileSize;
    private $maxFiles;
    private $logLevel;
    
    // Niveles de log
    const DEBUG = 0;
    const INFO = 1;
    const WARNING = 2;
    const ERROR = 3;
    const CRITICAL = 4;
    
    private $levelNames = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
        self::CRITICAL => 'CRITICAL'
    ];
    
    private function __construct() {
        $this->logPath = env('LOG_PATH', '/var/log/bluecat');
        $this->maxFileSize = $this->parseSize(env('LOG_MAX_SIZE', '10M'));
        $this->maxFiles = (int)env('LOG_MAX_FILES', 10);
        $this->logLevel = $this->parseLevel(env('LOG_LEVEL', 'INFO'));
        
        // Crear directorio de logs si no existe
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function parseLevel($level) {
        $levels = [
            'DEBUG' => self::DEBUG,
            'INFO' => self::INFO,
            'WARNING' => self::WARNING,
            'ERROR' => self::ERROR,
            'CRITICAL' => self::CRITICAL
        ];
        return $levels[strtoupper($level)] ?? self::INFO;
    }
    
    private function parseSize($size) {
        $size = strtoupper(trim($size));
        $unit = substr($size, -1);
        $value = (int)$size;
        
        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return $value;
        }
    }
    
    /**
     * Registrar mensaje de log
     */
    public function log($level, $message, $context = []) {
        // Verificar nivel de log
        if ($level < $this->logLevel) {
            return;
        }
        
        // Preparar entrada de log
        $logEntry = $this->formatLogEntry($level, $message, $context);
        
        // Escribir en archivo
        $this->writeToFile($logEntry);
        
        // Si es ERROR o CRITICAL, también escribir en error_log de PHP
        if ($level >= self::ERROR) {
            error_log($logEntry);
        }
    }
    
    /**
     * Formatear entrada de log
     */
    private function formatLogEntry($level, $message, $context) {
        $timestamp = date('Y-m-d H:i:s');
        $levelName = $this->levelNames[$level] ?? 'UNKNOWN';
        
        // Obtener información del request
        $requestId = $this->getRequestId();
        $userId = $this->getUserId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        
        // Formatear mensaje
        $entry = sprintf(
            "[%s] [%s] [Request:%s] [User:%s] [IP:%s] [%s %s] %s",
            $timestamp,
            $levelName,
            $requestId,
            $userId,
            $ip,
            $method,
            $uri,
            $message
        );
        
        // Agregar contexto si existe
        if (!empty($context)) {
            $entry .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        return $entry . PHP_EOL;
    }
    
    /**
     * Escribir en archivo de log
     */
    private function writeToFile($logEntry) {
        $logFile = $this->logPath . '/app_' . date('Y-m-d') . '.log';
        
        // Verificar rotación
        $this->rotateIfNeeded($logFile);
        
        // Escribir
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Rotar archivos de log si es necesario
     */
    private function rotateIfNeeded($logFile) {
        if (!file_exists($logFile)) {
            return;
        }
        
        // Verificar tamaño
        if (filesize($logFile) < $this->maxFileSize) {
            return;
        }
        
        // Rotar archivos
        for ($i = $this->maxFiles - 1; $i > 0; $i--) {
            $oldFile = $logFile . '.' . $i;
            $newFile = $logFile . '.' . ($i + 1);
            
            if (file_exists($oldFile)) {
                if ($i === $this->maxFiles - 1) {
                    unlink($oldFile); // Eliminar el más antiguo
                } else {
                    rename($oldFile, $newFile);
                }
            }
        }
        
        // Renombrar archivo actual
        rename($logFile, $logFile . '.1');
    }
    
    /**
     * Obtener ID de request único
     */
    private function getRequestId() {
        if (!isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $_SERVER['HTTP_X_REQUEST_ID'] = uniqid('req_', true);
        }
        return $_SERVER['HTTP_X_REQUEST_ID'];
    }
    
    /**
     * Obtener ID de usuario
     */
    private function getUserId() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return $_SESSION['user_id'] ?? 'anonymous';
    }
    
    // Métodos de conveniencia
    public function debug($message, $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    public function info($message, $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log(self::WARNING, $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    public function critical($message, $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    /**
     * Log de auditoría para acciones de usuario
     */
    public function audit($action, $entity, $entityId, $details = []) {
        $context = [
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'details' => $details
        ];
        
        $message = sprintf(
            "AUDIT: %s %s #%s",
            $action,
            $entity,
            $entityId
        );
        
        $this->info($message, $context);
    }
    
    /**
     * Log de seguridad
     */
    public function security($event, $details = []) {
        $context = [
            'event' => $event,
            'details' => $details
        ];
        
        $message = sprintf("SECURITY: %s", $event);
        
        $this->warning($message, $context);
    }
}

// Función global de conveniencia
function logger() {
    return Logger::getInstance();
}
?>
