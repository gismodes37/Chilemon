<?php
/**
 * Autocargador ligero nativo PSR-4.
 * Elimina la necesidad de Composer y de usar require_once múltiples manuales.
 */
spl_autoload_register(function ($class) {
    // Definimos el prefijo del espacio de nombres principal
    $prefix = 'App\\';

    // Directorio base para el prefijo del espacio de nombres principal
    $base_dir = __DIR__ . '/';

    // Verifica si la clase utiliza el prefijo aplicable
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No corresponde a nuestro prefijo, vamos al siguiente autoloader (si existe)
        return;
    }

    // Obtener el nombre de la clase relativo
    $relative_class = substr($class, $len);

    // Reemplaza los separadores de "namespace" con separadores de directorios en el
    // nombre de la clase relativo, y le añade el .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // Si el archivo existe, requerirlo
    if (file_exists($file)) {
        require $file;
    }
});
