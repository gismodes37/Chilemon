<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AslRptService;
use RuntimeException;

/**
 * SystemController — Ejecuta acciones de administración del sistema
 * de forma segura a través del wrapper chilemon-rpt.
 *
 * Acciones permitidas:
 *   restart-asterisk  → reinicia el servicio Asterisk
 *   restart-apache    → reinicia Apache (¡ojo! la respuesta puede no llegar)
 *   poweroff          → apaga el nodo completamente
 */
final class SystemController
{
    /** Mapeo de acciones UI → métodos del servicio. */
    private const ACTION_MAP = [
        'restart-asterisk' => 'restartAsterisk',
        'restart-apache'   => 'restartApache',
        'poweroff'         => 'powerOff',
    ];

    private AslRptService $svc;

    public function __construct()
    {
        $this->svc = new AslRptService();
    }

    /**
     * Ejecuta una acción de sistema.
     *
     * @param string $action Clave de ACTION_MAP.
     * @return array{success: bool, action: string, message?: string}
     * @throws RuntimeException si la acción falla en el wrapper.
     */
    public function execute(string $action): array
    {
        if (!isset(self::ACTION_MAP[$action])) {
            return [
                'success' => false,
                'error'   => "Acción no reconocida: {$action}",
            ];
        }

        $method = self::ACTION_MAP[$action];
        $this->svc->$method();

        return [
            'success' => true,
            'action'  => $action,
            'message' => "Acción '{$action}' ejecutada correctamente.",
        ];
    }
}
