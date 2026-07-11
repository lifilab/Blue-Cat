<?php
/**
 * Helper para cargar variables de entorno desde archivo .env
 * Blue-Cat ERP
 */

function loadEnv($path = null) {
    if ($path === null) {
        $path = dirname(__DIR__) . '/.env';
    }
    
    if (!file_exists($path)) {
        // Si no existe .env, usar valores por defecto para desarrollo
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Ignorar comentarios
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parsear línea
        if (strpos($line, '=') === false) {
            continue;
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Remover comillas
        if (preg_match('/^"(.*)"$/', $value, $matches)) {
            $value = $matches[1];
        } elseif (preg_match("/^'(.*)'$/", $value, $matches)) {
            $value = $matches[1];
        }
        
        // Establecer variable de entorno
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
    
    return true;
}

/**
 * Obtener variable de entorno con valor por defecto
 */
function env($key, $default = null) {
    $value = getenv($key);
    
    if ($value === false) {
        return $default;
    }
    
    // Convertir valores booleanos
    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return null;
    }
    
    return $value;
}

/**
 * Verificar que todas las variables requeridas estén configuradas
 */
function checkRequiredEnv($required = []) {
    $missing = [];
    
    foreach ($required as $key) {
        if (!env($key)) {
            $missing[] = $key;
        }
    }
    
    if (!empty($missing)) {
        throw new Exception("Variables de entorno faltantes: " . implode(', ', $missing));
    }
    
    return true;
}

// Cargar variables de entorno automáticamente
loadEnv();
?>
