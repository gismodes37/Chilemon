<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AslRptService;
use RuntimeException;

/**
 * SystemController — Punto de entrada de acciones de administración del sistema.
 *
 * Las acciones destructivas (restart de Asterisk/Apache y poweroff) ya no se
 * exponen por la web: se ejecutan por SSH con sudo real. El mapa está vacío a
 * propósito, de modo que cualquier action recibida se rechaza de forma
 * explícita con "Acción no reconocida".
 */
final class SystemController
{
    /** No hay acciones de sistema disponibles desde el dashboard. */
    private const ACTION_MAP = [];

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
