/**
 * =============================================================
 * ChileMon — dashboard.js
 * =============================================================
 * Archivo principal de lógica del dashboard.
 *
 * ÍNDICE DE SECCIONES:
 *   1.  Configuración base
 *   2.  CSRF — helpers para seguridad en formularios
 *   3.  HTTP helpers — postForm() y getJson()
 *   4.  Sesión expirada — handleUnauthorized()
 *   5.  Helpers UI — escapeHtml(), setButtonLoading()
 *   6.  Tema claro/oscuro — setThemeUI(), toggleTheme()
 *   7.  Refresco del dashboard — reloadDashboard(), refreshNodesLive()
 *   8.  Acciones de nodos — connect, disconnect, delete
 *   9.  Favoritos — modal, CRUD, conectar desde favorito
 *  10.  Botón Actualizar manual
 *  11.  Auto refresh automático — startChilemonAutoRefresh()
 *  12.  Init — DOMContentLoaded
 *
 * DEPENDENCIAS:
 *   - Bootstrap 5 (window.bootstrap para modales)
 *   - Bootstrap Icons (clases bi-*)
 *   - window.CHILEMON_BASE definido en la vista PHP antes de este script
 *
 * CONVENCIONES:
 *   - Toda llamada a API pasa por postForm() o getJson().
 *   - Ambas detectan 401 y delegan a handleUnauthorized().
 *   - Los catch solo muestran alert si el error NO es "Unauthorized"
 *     para evitar mensajes innecesarios cuando la sesión expira.
 * =============================================================
 */

/* =============================================================
 * 1. CONFIGURACIÓN BASE
 * -------------------------------------------------------------
 * window.CHILEMON_BASE se inyecta desde la vista PHP así:
 *   <script>window.CHILEMON_BASE = "<?= BASE_URL ?>";</script>
 * Si no existe, se usa "./" como fallback seguro.
 * Todas las URLs de fetch se construyen como: base + "api/..."
 * ============================================================= */
const base = window.CHILEMON_BASE || "./";

/* =============================================================
 * 2. CSRF — HELPERS PARA SEGURIDAD EN FORMULARIOS
 * -------------------------------------------------------------
 * El token CSRF se genera en PHP, se inyecta en:
 *   <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
 * y se adjunta automáticamente a cada POST.
 * El servidor valida este token antes de procesar la petición.
 * ============================================================= */

/**
 * Lee el token CSRF desde el <meta> del HTML.
 * Retorna string vacío si el meta no existe (degradación segura).
 *
 * @returns {string} Token CSRF o "" si no se encuentra.
 */
function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute("content") || "" : "";
}

/* =============================================================
 * 3. HTTP HELPERS — postForm() y getJson()
 * -------------------------------------------------------------
 * Toda comunicación con la API pasa por estas dos funciones.
 * Ambas:
 *   - Incluyen credentials: "same-origin" (envía la cookie de sesión PHP).
 *   - Detectan HTTP 401 → llaman a handleUnauthorized().
 *   - Lanzan Error con el mensaje del servidor o "HTTP {status}".
 * ============================================================= */

/**
 * Envía un POST con Content-Type: application/x-www-form-urlencoded.
 * Adjunta el token CSRF automáticamente si existe.
 *
 * @param {string} url     - Ruta relativa a `base`, ej: "api/connect.php"
 * @param {Object} dataObj - Pares clave/valor a enviar en el body.
 * @returns {Promise<Object|null>} JSON de respuesta del servidor.
 * @throws {Error} Si la respuesta no es OK (incluye 401).
 */
async function postForm(url, dataObj) {
  // Construir el body como URL-encoded (compatible con $_POST en PHP)
  const form = new URLSearchParams();
  Object.entries(dataObj).forEach(([k, v]) => {
    form.append(k, v);
  });

  // Adjuntar CSRF al body antes de enviar
  const csrf = getCsrfToken();
  if (csrf) {
    form.append("csrf_token", csrf);
  }

  const res = await fetch(base + url, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
    },
    body: form.toString(),
    credentials: "same-origin", // envía cookie PHPSESSID con cada request
  });

  // Intentar parsear JSON aunque la respuesta sea un error
  // (el servidor siempre devuelve JSON con { success, error })
  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    json = null;
  }

  // Sesión expirada o no autenticado → redirigir a login y abortar
  if (res.status === 401) {
    handleUnauthorized();
    throw new Error("Unauthorized");
  }

  // Cualquier otro error HTTP → extraer mensaje del JSON o usar genérico
  if (!res.ok) {
    const msg =
      json && (json.error || json.message)
        ? json.error || json.message
        : `HTTP ${res.status}`;
    throw new Error(msg);
  }

  return json;
}

/**
 * Realiza un GET y retorna el JSON de respuesta.
 * Usado para consultar endpoints de lectura (nodos, favoritos, etc.).
 *
 * @param {string} url - Ruta relativa a `base`, ej: "api/ami/nodes.php"
 * @returns {Promise<Object|null>} JSON de respuesta del servidor.
 * @throws {Error} Si la respuesta no es OK (incluye 401).
 */
async function getJson(url) {
  const res = await fetch(base + url, {
    method: "GET",
    credentials: "same-origin", // envía cookie PHPSESSID con cada request
    headers: { Accept: "application/json" },
  });

  // Intentar parsear JSON aunque la respuesta sea un error
  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    json = null;
  }

  // Sesión expirada o no autenticado → redirigir a login y abortar
  if (res.status === 401) {
    handleUnauthorized();
    throw new Error("Unauthorized");
  }

  // Cualquier otro error HTTP → extraer mensaje del JSON o usar genérico
  if (!res.ok) {
    const msg =
      json && (json.error || json.message)
        ? json.error || json.message
        : `HTTP ${res.status}`;
    throw new Error(msg);
  }

  return json;
}

/* =============================================================
 * 4. SESIÓN EXPIRADA — handleUnauthorized()
 * -------------------------------------------------------------
 * Centraliza la reacción cuando cualquier endpoint devuelve 401.
 *
 * Flujo:
 *   API devuelve 401
 *     → postForm() / getJson() detecta res.status === 401
 *     → llama a handleUnauthorized()
 *     → stopChilemonAutoRefresh()  ← cancela el setInterval
 *     → window.location.href = login.php  ← redirige al usuario
 *     → throw new Error("Unauthorized")   ← aborta el caller
 *
 * El flag _unauthorizedHandled evita que múltiples requests
 * concurrentes (auto refresh + acción manual) dupliquen la
 * redirección o muestren múltiples alerts.
 * ============================================================= */

/** Flag para garantizar ejecución única de handleUnauthorized(). */
let _unauthorizedHandled = false;

/**
 * Detiene el auto refresh y redirige al login.
 * Solo se ejecuta una vez aunque sea llamada múltiples veces.
 */
function handleUnauthorized() {
  if (_unauthorizedHandled) return; // ya en proceso de redirección
  _unauthorizedHandled = true;

  // Detener el intervalo de auto refresh antes de redirigir
  stopChilemonAutoRefresh();

  // Construir URL de login.
  // window.CHILEMON_BASE apunta a public/ (ej: "https://node.local/chilemon/")
  // login.php está en public/ → mismo nivel → base + "login.php"
  // Ejemplo: "https://nodeYOUR_NODE.local/chilemon/" + "login.php"
  //        = "https://nodeYOUR_NODE.local/chilemon/login.php"  ✓
  const loginUrl = base.replace(/\/+$/, "/") + "login.php";
  window.location.href = loginUrl;
}

/* =============================================================
 * 5. HELPERS UI
 * -------------------------------------------------------------
 * Utilidades reutilizables para manipulación del DOM.
 * ============================================================= */

/**
 * Escapa caracteres especiales HTML para evitar XSS al insertar
 * datos del servidor (nodos, aliases, descripciones) en innerHTML.
 *
 * @param {*} s - Valor a escapar (se convierte a string).
 * @returns {string} String con entidades HTML seguras.
 */
function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

/**
 * Muestra u oculta el estado de carga en un botón Bootstrap.
 * Guarda el HTML original en data-original-html para restaurarlo.
 *
 * Uso:
 *   setButtonLoading(btn, true, "Conectando...");  // deshabilita + spinner
 *   setButtonLoading(btn, false);                  // restaura estado original
 *
 * @param {HTMLElement|null} button      - El botón a modificar.
 * @param {boolean}          isLoading   - true = activar spinner, false = restaurar.
 * @param {string}           loadingText - Texto junto al spinner (default: "Procesando...").
 */
function setButtonLoading(button, isLoading, loadingText = "Procesando...") {
  if (!button) return;

  if (isLoading) {
    // Guardar HTML original solo la primera vez (evita sobreescribir con el spinner)
    if (!button.dataset.originalHtml) {
      button.dataset.originalHtml = button.innerHTML;
    }
    button.disabled = true;
    button.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${loadingText}`;
  } else {
    button.disabled = false;
    // Restaurar el HTML guardado (icono + texto original)
    if (button.dataset.originalHtml) {
      button.innerHTML = button.dataset.originalHtml;
    }
  }
}

/* =============================================================
 * v0.2.x — Actividad RX/TX en vivo
 * -------------------------------------------------------------
 * Mapa para detectar delta de TX entre lecturas SSE.
 * Si tx_time_today cambió entre dos lecturas → TX reciente.
 * ============================================================= */
let chilemonPrevTxTime = new Map();

/**
 * Genera el HTML del badge de actividad para un nodo.
 *
 * Prioridad de estados:
 *   1. RX activo  (activity.rx === true)     → verde pulsante
 *   2. TX reciente (tx_time cambió)          → rojo pulsante
 *   3. Activo     (keyups_today > 0)         → azul tenue
 *   4. Idle       (nada)                     → gris
 *
 * @param {Object} activity       - {rx, tx_time_today, keyups_today, ...}
 * @param {string} nodeId         - ID del nodo para tracking de TX delta
 * @returns {string} HTML del badge
 */
function renderActivityBadge(activity, nodeId) {
  if (!activity) {
    return `<span class="badge badge-activity-idle"><span class="activity-dot"></span> Idle</span>`;
  }

  const rx = !!activity.rx;
  const txTimeNow = Number(activity.tx_time_today || 0);
  const keyups = Number(activity.keyups_today || 0);

  // Detectar TX reciente: comparar con valor anterior
  const prevTx = chilemonPrevTxTime.get(nodeId) ?? null;
  const txRecent = prevTx !== null && txTimeNow > prevTx;
  chilemonPrevTxTime.set(nodeId, txTimeNow);

  // Prioridad: RX > TX > Activo > Idle
  if (rx) {
    return `<span class="badge badge-activity-rx"><span class="activity-dot"></span> RX</span>`;
  }
  if (txRecent) {
    return `<span class="badge badge-activity-tx"><span class="activity-dot"></span> TX</span>`;
  }
  if (keyups > 0) {
    return `<span class="badge badge-activity-recent"><span class="activity-dot"></span> Activo</span>`;
  }
  return `<span class="badge badge-activity-idle"><span class="activity-dot"></span> Idle</span>`;
}

function renderNodes(nodes) {
  const tbody = document.getElementById("nodes-table-body");
  if (!tbody) return;

  if (!Array.isArray(nodes) || nodes.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="9" class="text-center py-3 text-muted">
          Sin nodos conectados
        </td>
      </tr>`;
    return;
  }

  tbody.innerHTML = nodes
    .map((n) => {
      const nodeId = escapeHtml(n.node || "");
      const isFav = !!n.is_favorite;
      const alias = n.alias || "";
      const online = n.online ? "Sí" : "--";
      const activityHtml = renderActivityBadge(n.activity || null, n.node || "");

      // Mostrar alias o info del nodo. Si es favorito, mostrar estrella junto al ID.
      const nodeDisplayId = isFav 
        ? `<i class="bi bi-star-fill text-warning me-1 small"></i>${nodeId}` 
        : nodeId;

      return `
  <tr
    id="node-row-${nodeId}"
    class="${n.visibility_type === "direct" ? "node-row-direct" : "node-row-visible"}">

    <td class="fw-bold font-monospace">${nodeDisplayId}</td>

    <td>${escapeHtml(n.info || "")}</td>

    <td>${escapeHtml(n.received || "--")}</td>

    <td>${activityHtml}</td>

    <td>
      <span class="badge ${n.visibility_type === "direct" ? "bg-success" : "bg-info"}">
        ${escapeHtml(
          n.visibility_type === "direct"
            ? "DIRECTO"
            : n.visibility_type === "visible"
              ? "VISIBLE"
              : "DESCONOCIDO",
        )}
      </span>
    </td>

    <td>
      ${
        n.direction
          ? `<span class="badge bg-info">${escapeHtml(n.direction)}</span>`
          : `<span class="text-muted">--</span>`
      }
    </td>

    <td>${escapeHtml(n.connected || "--")}</td>

    <td>
      <span class="badge bg-secondary">${escapeHtml(n.mode || "ASL")}</span>
    </td>

    <td>
      <div class="btn-group btn-group-sm">

        <button class="btn btn-connect"
          onclick="connectToSpecificNode('${nodeId}')"
          title="Conectar a este nodo">
          <i class="bi bi-telephone"></i>
        </button>

        <button class="btn btn-outline-warning"
          onclick="toggleFavoriteNode('${nodeId}', ${isFav})"
          title="${isFav ? 'Quitar de favoritos' : 'Añadir a favoritos'}">
          <i class="bi ${isFav ? "bi-star-fill text-warning" : "bi-star"}"></i>
        </button>

        <button class="btn btn-outline-danger"
          onclick="disconnectFromNodeConfirm('${nodeId}')"
          title="Desconectar este nodo">
          <i class="bi bi-telephone-x"></i>
        </button>

      </div>
    </td>

  </tr>
`;
    })
    .join("");
}

let chilemonPreviousNodeMap = new Map();

function buildNodeMap(nodes) {
  const map = new Map();

  if (!Array.isArray(nodes)) return map;

  for (const n of nodes) {
    const id = String(n.node || n.node_id || "").trim();
    if (!id) continue;

    const act = n.activity || {};

    map.set(id, {
      node: id,
      link: String(n.link || ""),
      direction: String(n.direction || ""),
      connected: String(n.connected || ""),
      mode: String(n.mode || ""),
      online: !!n.online,
      visibility_type: String(n.visibility_type || ""),
      remote_count: Number(n.remote_count || 0),
      // v0.2.x — campos de actividad para detección de cambios
      activity_rx: !!act.rx,
      activity_tx: Number(act.tx_time_today || 0),
      activity_keyups: Number(act.keyups_today || 0),
    });
  }

  return map;
}

function markNodeRowAlive(nodeId, isNew = false) {
  const row = document.getElementById(`node-row-${nodeId}`);
  if (!row) return;

  row.classList.remove("node-row-active", "node-row-new");

  // Forzar reflow para reiniciar animación
  void row.offsetWidth;

  row.classList.add("node-row-active");
  if (isNew) {
    row.classList.add("node-row-new");
  }

  setTimeout(() => {
    row.classList.remove("node-row-active");
    row.classList.remove("node-row-new");
  }, 3500);
}

function detectChangedNodes(previousMap, nextNodes) {
  const changed = [];
  const nextMap = buildNodeMap(nextNodes);

  for (const [nodeId, nextNode] of nextMap.entries()) {
    const prevNode = previousMap.get(nodeId);

    if (!prevNode) {
      changed.push({ nodeId, isNew: true });
      continue;
    }

    const changedFields =
      prevNode.link !== nextNode.link ||
      prevNode.direction !== nextNode.direction ||
      prevNode.connected !== nextNode.connected ||
      prevNode.mode !== nextNode.mode ||
      prevNode.online !== nextNode.online ||
      prevNode.visibility_type !== nextNode.visibility_type ||
      prevNode.remote_count !== nextNode.remote_count ||
      // v0.2.x — detectar cambios de actividad
      prevNode.activity_rx !== nextNode.activity_rx ||
      prevNode.activity_tx !== nextNode.activity_tx ||
      prevNode.activity_keyups !== nextNode.activity_keyups;

    if (changedFields) {
      changed.push({ nodeId, isNew: false });
    }
  }

  return {
    changed,
    nextMap,
  };
}

function openRemoteNodesModal(directNode, remoteNodes, remoteScope = "direct") {
  const modalNode = document.getElementById("remoteNodesModalNode");
  const modalBody = document.getElementById("remoteNodesModalBody");
  const modalSummary = document.getElementById("remoteNodesModalSummary");
  const modalEl = document.getElementById("remoteNodesModal");

  if (
    !modalNode ||
    !modalBody ||
    !modalSummary ||
    !modalEl ||
    !window.bootstrap
  ) {
    return;
  }

  modalNode.textContent = directNode;

  const nodes = Array.isArray(remoteNodes) ? remoteNodes : [];
  const isGlobal = remoteScope === "global";

  modalSummary.textContent = isGlobal
    ? `Hay múltiples enlaces directos. Esta lista combina ${nodes.length} nodos visibles de la topología global actual.`
    : `${nodes.length} nodos visibles en la red remota enlazada.`;

  if (nodes.length === 0) {
    modalBody.innerHTML = `
      <tr>
        <td colspan="2" class="text-muted">No se detectaron nodos remotos visibles.</td>
      </tr>
    `;
  } else {
    modalBody.innerHTML = nodes
      .map(
        (nodeId) => `
      <tr>
        <td class="fw-semibold font-monospace">${escapeHtml(nodeId)}</td>
        <td class="text-muted">${
          isGlobal
            ? "Visible en la topología global actual"
            : `Visible vía enlace con ${escapeHtml(directNode)}`
        }</td>
      </tr>
    `,
      )
      .join("");
  }

  new window.bootstrap.Modal(modalEl).show();
}

/* =============================================================
 * 6. TEMA CLARO / OSCURO
 * -------------------------------------------------------------
 * Bootstrap 5 usa data-bs-theme="dark|light" en <html>.
 * La preferencia se persiste en una cookie (1 año) para que
 * PHP la lea en el siguiente render y aplique el tema desde
 * el servidor sin parpadeo inicial.
 *
 * Elementos del DOM controlados por atributos data-*:
 *   data-theme-icon  → contenedor del <i> con clase bi-sun / bi-moon-stars
 *   data-theme-text  → elemento con texto "Tema claro" / "Tema oscuro"
 *   data-theme-title → elemento con atributo title (tooltip)
 * ============================================================= */

/**
 * Actualiza los iconos y textos del botón de tema en toda la UI.
 * Se llama tanto en toggleTheme() como en el init para sincronizar
 * el estado inicial (cuando PHP ya aplicó el tema vía cookie).
 *
 * @param {boolean} isDark - true si el tema activo es oscuro.
 */
function setThemeUI(isDark) {
  // Cambiar icono: sol en modo oscuro (para cambiar a claro), luna en modo claro
  document.querySelectorAll("[data-theme-icon] i").forEach((icon) => {
    icon.classList.remove("bi-sun", "bi-moon-stars");
    icon.classList.add(isDark ? "bi-sun" : "bi-moon-stars");
  });

  // Actualizar texto del botón
  document.querySelectorAll("[data-theme-text]").forEach((el) => {
    el.textContent = isDark ? "Tema claro" : "Tema oscuro";
  });

  // Actualizar tooltip
  document.querySelectorAll("[data-theme-title]").forEach((el) => {
    el.setAttribute("title", isDark ? "Tema claro" : "Tema oscuro");
  });
}

/**
 * Alterna entre tema claro y oscuro.
 * Llamado desde onclick="toggleTheme()" en el botón del header.
 * Persiste la preferencia en cookie "chilemon_darkmode".
 */
function toggleTheme() {
  const html = document.documentElement;
  const isDark = html.getAttribute("data-bs-theme") === "dark";
  const nextDark = !isDark;

  // Aplicar nuevo tema en el atributo HTML (Bootstrap lo detecta automáticamente)
  html.setAttribute("data-bs-theme", nextDark ? "dark" : "light");

  // Persistir en cookie para que PHP aplique el tema en el próximo render
  // max-age=31536000 = 1 año en segundos
  document.cookie = `chilemon_darkmode=${nextDark}; path=/; max-age=31536000`;

  // Sincronizar iconos y textos del botón
  setThemeUI(nextDark);
}

/* =============================================================
 * 7. REFRESCO DEL DASHBOARD
 * -------------------------------------------------------------
 * Dos modalidades de refresco:
 *
 * A) reloadDashboard() — recarga completa de la página.
 *    Garantiza consistencia total (tabla + stats + actividad).
 *    Es el método seguro actual.
 *
 * B) refreshNodesLive() — consulta el endpoint antes de recargar.
 *    Verifica que Asterisk ya refleja el cambio para evitar
 *    recargar y mostrar un estado desactualizado.
 *
 * afterNodeActionLive() añade un delay de 1.2s después de
 * connect/disconnect/delete para dar tiempo a SQLite y Asterisk.
 * ============================================================= */

/**
 * Recarga completa de la página.
 * Método actual estable: garantiza que tabla, estadísticas
 * y actividad reciente queden sincronizadas.
 *
 * TODO (Milestone 3+): reemplazar por render parcial via fetch
 * para mejorar UX sin parpadeo de página.
 */
function reloadDashboard() {
  window.location.reload();
}

/**
 * Verifica contra el endpoint AMI que el estado ya cambió
 * antes de recargar la vista.
 * Si el endpoint falla por error de red (no por 401), no recarga
 * para evitar mostrar un estado inconsistente.
 */
async function refreshNodesLive() {
  try {
    const json = await getJson("api/nodes.php");

    if (!json || !json.ok) return;

    reloadDashboard();
  } catch (e) {
    if (e.message !== "Unauthorized") {
      console.error("Error refrescando nodos:", e);
    }
  }
}

/**
 * Punto de entrada después de cualquier acción sobre un nodo.
 * El delay de 1200ms da margen para que:
 *   - Asterisk procese el comando AMI
 *   - SQLite actualice el registro
 * antes de que la vista consulte el estado.
 */
function afterNodeActionLive() {
  setTimeout(() => {
    reloadDashboard();
  }, 2000);
}

/* =============================================================
 * 8. ACCIONES DE NODOS — connect, disconnect, delete
 * -------------------------------------------------------------
 * Todas las acciones siguen el mismo patrón:
 *   1. Validar input
 *   2. Llamar a postForm() con el endpoint correspondiente
 *   3. En éxito → afterNodeActionLive() (delay + refresh)
 *   4. En error → alert solo si NO es "Unauthorized"
 *
 * Las variantes "Confirm" muestran un confirm() antes de ejecutar.
 * ============================================================= */

/**
 * Conecta al nodo ingresado en el campo #node-number del panel.
 * Muestra spinner en el botón durante la petición.
 * Llamado desde onclick="connectToNode()" en el formulario del header.
 */
async function connectToNode() {
  const input = document.getElementById("node-number");
  const node = input ? (input.value || "").trim() : "";

  if (!node) {
    alert("Ingresa un número de nodo.");
    return;
  }

  // Botón de Conectar en el panel de control (selector CSS del layout actual)
  const button = document.querySelector(".control-panel .btn.btn-success");

  try {
    setButtonLoading(button, true, "Conectando...");
    await postForm("api/connect.php", { node });

    // Limpiar el campo después de enviar exitosamente
    if (input) input.value = "";
    afterNodeActionLive();
  } catch (e) {
    if (e.message !== "Unauthorized") {
      alert(`Error al conectar: ${e.message}`);
    }
    // Restaurar botón si hubo error (en éxito la página se recarga sola)
    setButtonLoading(button, false);
  }
}

/**
 * Conecta a un nodo específico dado su ID.
 * Usado desde botones de la tabla de nodos o desde favoritos.
 *
 * @param {string|number} nodeId - ID del nodo a conectar.
 */
async function connectToSpecificNode(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  try {
    await postForm("api/connect.php", { node });
    afterNodeActionLive();
  } catch (e) {
    if (e.message !== "Unauthorized") {
      alert(`Error al conectar: ${e.message}`);
    }
  }
}

/**
 * Desconecta un nodo activo dado su ID.
 * Llamado directamente (sin confirm) desde disconnectFromNodeConfirm().
 *
 * @param {string|number} nodeId - ID del nodo a desconectar.
 */
async function disconnectFromNode(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  try {
    await postForm("api/disconnect.php", { node });
    afterNodeActionLive();
  } catch (e) {
    if (e.message !== "Unauthorized") {
      alert(`Error al desconectar: ${e.message}`);
    }
  }
}

/**
 * Pide confirmación al usuario antes de desconectar.
 * Llamado desde el botón "Desconectar" en la tabla de nodos.
 *
 * @param {string|number} nodeId - ID del nodo a desconectar.
 */
function disconnectFromNodeConfirm(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  if (!confirm(`¿Seguro que deseas desconectar el nodo ${node}?`)) return;
  disconnectFromNode(node);
}

/**
 * Elimina un nodo del dashboard (solo del registro en SQLite,
 * NO afecta la configuración de Asterisk/ASL).
 * Llamado directamente (sin confirm) desde deleteNodeConfirm().
 *
 * @param {string|number} nodeId - ID del nodo a eliminar.
 */
async function deleteNode(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  try {
    await postForm("api/delete_node.php", { node });
    afterNodeActionLive();
  } catch (e) {
    if (e.message !== "Unauthorized") {
      alert(`Error al eliminar: ${e.message}`);
    }
  }
}

/**
 * Pide confirmación antes de eliminar un nodo del dashboard.
 * Llamado desde el botón "Eliminar" en la tabla de nodos.
 *
 * @param {string|number} nodeId - ID del nodo a eliminar.
 */
function deleteNodeConfirm(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  if (
    !confirm(`⚠️ Esto eliminará el nodo ${node} del dashboard.\n¿Confirmas?`)
  ) {
    return;
  }

  deleteNode(nodeId);
}

/* =============================================================
 * 9. FAVORITOS — Modal Bootstrap 5 + CRUD + conectar
 * -------------------------------------------------------------
 * El modal #favoritesModal se define en dashboard.php.
 * setupFavoritesModal() enlaza todos los eventos del modal
 * en el DOMContentLoaded.
 *
 * Flujo:
 *   Modal se abre → favoritesReload() carga lista desde API
 *   Formulario submit → postForm(api/favorites.php, {action: upsert})
 *   Botón Conectar → postForm(api/connect.php) + cierra modal
 *   Botón Eliminar → postForm(api/favorites.php, {action: delete})
 * ============================================================= */

/**
 * Inicializa todos los eventos del modal de favoritos.
 * Debe llamarse una sola vez en DOMContentLoaded.
 */
function setupFavoritesModal() {
  const modalEl = document.getElementById("favoritesModal");
  if (!modalEl) return; // el modal no existe en esta vista, salir sin error

  // Recargar la lista cada vez que el modal se abre
  modalEl.addEventListener("show.bs.modal", () => {
    favoritesReload();
  });

  // Botón de recargar lista manualmente dentro del modal
  const btnReload = document.getElementById("fav_reload");
  if (btnReload) {
    btnReload.addEventListener("click", (e) => {
      e.preventDefault();
      favoritesReload();
    });
  }

  // Botón de limpiar el formulario de agregar/editar favorito
  const btnClear = document.getElementById("fav_clear");
  if (btnClear) {
    btnClear.addEventListener("click", (e) => {
      e.preventDefault();
      favoritesClearForm();
    });
  }

  const form = document.getElementById("favForm");
  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault(); // evitar submit HTML nativo

      // Leer campos del formulario
      const node_id = (
        document.getElementById("fav_node_id")?.value || ""
      ).trim();
      const alias = (document.getElementById("fav_alias")?.value || "").trim();
      const description = (
        document.getElementById("fav_desc")?.value || ""
      ).trim();

      if (!node_id) {
        alert("Nodo requerido.");
        return;
      }

      try {
        await postForm("api/favorites/save.php", {
          node_id,
          alias,
          description,
        });

        favoritesClearForm(); // limpiar formulario tras guardar
        await favoritesReload(); // refrescar tabla de favoritos
        
        // ¡Ocultar el modal automáticamente para que sea obvio que funcionó!
        const modalEl = document.getElementById("favoritesModal");
        if (modalEl && window.bootstrap) {
          const inst = window.bootstrap.Modal.getInstance(modalEl);
          if (inst) inst.hide();
        }
        
        alert("¡Favorito guardado correctamente en tu base de datos!");
      } catch (err) {
        if (err.message !== "Unauthorized") {
          alert(`Error guardando favorito: ${err.message}`);
        }
      }
    });
  }
}

/**
 * Limpia los campos del formulario de favoritos.
 * Llamado tras guardar un favorito o al pulsar "Limpiar".
 */
function favoritesClearForm() {
  const a = document.getElementById("fav_node_id");
  const b = document.getElementById("fav_alias");
  const c = document.getElementById("fav_desc");

  if (a) a.value = "";
  if (b) b.value = "";
  if (c) c.value = "";
}

/**
 * Carga la lista de favoritos del usuario desde la API
 * y actualiza el tbody #fav_tbody del modal.
 * Muestra estado de carga mientras espera la respuesta.
 */
async function favoritesReload() {
  const tbody = document.getElementById("fav_tbody");
  if (!tbody) return;

  // Mostrar indicador de carga mientras llega la respuesta
  tbody.innerHTML = `<tr><td colspan="4" class="text-muted">Cargando...</td></tr>`;

  try {
    // GET api/favorites/list.php → { success: true, items: [...] }
    const json = await getJson("api/favorites/list.php");
    const items = json && json.items ? json.items : [];
    renderFavorites(items);
  } catch (err) {
    if (err.message !== "Unauthorized") {
      tbody.innerHTML = `<tr><td colspan="4" class="text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
    }
  }
}

/**
 * Renderiza la lista de favoritos en el tbody del modal.
 * Enlaza los botones "Conectar" y "Eliminar" con delegación de eventos.
 *
 * Seguridad: todos los valores del servidor se pasan por escapeHtml()
 * antes de insertarlos en innerHTML.
 *
 * @param {Array} items - Array de objetos { node_id, alias, description }.
 */
function renderFavorites(items) {
  const tbody = document.getElementById("fav_tbody");
  if (!tbody) return;

  // Estado vacío
  if (!items || items.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-muted">Sin favoritos aún.</td></tr>`;
    return;
  }

  // Generar filas de la tabla con valores escapados
  tbody.innerHTML = items
    .map((it) => {
      const node = escapeHtml((it.node_id || "").toString());
      const alias = escapeHtml((it.alias || "").toString());
      const desc = escapeHtml((it.description || "").toString());

      // data-fav-connect y data-fav-delete son los selectores para
      // los event listeners que se enlazan justo debajo
      return `
      <tr>
        <td class="fw-semibold">${node}</td>
        <td>${alias}</td>
        <td class="text-muted">${desc}</td>
        <td class="text-end">
          <div class="btn-group btn-group-sm">
            <button class="btn btn-success" data-fav-connect="${node}">
              <i class="bi bi-telephone"></i> Conectar
            </button>
            <button class="btn btn-outline-danger" data-fav-delete="${node}">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
    })
    .join("");

  // Enlazar botones "Conectar" — conecta al nodo y cierra el modal
  tbody.querySelectorAll("[data-fav-connect]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const node = btn.getAttribute("data-fav-connect") || "";
      if (!confirm(`¿Conectar al nodo ${node}?`)) return;

      try {
        await postForm("api/connect.php", { node });

        // Cerrar el modal Bootstrap 5 programáticamente antes de recargar
        const modalEl = document.getElementById("favoritesModal");
        if (modalEl && window.bootstrap) {
          const inst = window.bootstrap.Modal.getInstance(modalEl);
          if (inst) inst.hide();
        }

        afterNodeActionLive();
      } catch (err) {
        if (err.message !== "Unauthorized") {
          alert(`Error al conectar: ${err.message}`);
        }
      }
    });
  });

  // Enlazar botones "Eliminar" — borra el favorito y recarga la lista
  tbody.querySelectorAll("[data-fav-delete]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const node = btn.getAttribute("data-fav-delete") || "";
      if (!confirm(`¿Eliminar favorito ${node}?`)) return;

      try {
        await postForm("api/favorites/delete.php", {
          node_id: node,
        });
        await favoritesReload(); // refrescar lista sin recargar la página
      } catch (err) {
        if (err.message !== "Unauthorized") {
          alert(`Error eliminando favorito: ${err.message}`);
        }
      }
    });
  });
}

/**
 * Añade o elimina un nodo de favoritos directamente desde la tabla.
 * No requiere recarga completa de la página ya que el auto-refresh
 * reflejará el cambio visual en la próxima lectura SSE.
 *
 * @param {string} nodeId - ID del nodo a togglear.
 * @param {boolean} isCurrentlyFav - Estado actual en la UI.
 */
async function toggleFavoriteNode(nodeId, isCurrentlyFav) {
  try {
    const endpoint = isCurrentlyFav ? "api/favorites/delete.php" : "api/favorites/save.php";
    const payload = {
      node_id: nodeId,
    };

    // Si es nuevo favorito, enviamos alias vacío (se usará el default del servidor)
    if (!isCurrentlyFav) {
      payload.alias = "";
      payload.description = "Añadido desde dashboard";
    }

    await postForm(endpoint, payload);

    // Refrescar inmediatamente el dashboard para ver el cambio visual (iconos/nombres)
    await refreshNodesLive();

  } catch (err) {
    if (err.message !== "Unauthorized") {
      alert(`Error con favoritos: ${err.message}`);
    }
  }
}

/* =============================================================
 * 10. BOTÓN ACTUALIZAR MANUAL
 * -------------------------------------------------------------
 * El botón "Actualizar" del dashboard llama a refreshSystemInfo().
 * Por ahora es un alias de reloadDashboard() (recarga completa).
 * En el futuro puede cambiarse por un refresh parcial sin recargar.
 * ============================================================= */

/**
 * Recarga el dashboard manualmente.
 * Llamado desde onclick="refreshSystemInfo()" en el botón del header.
 */
function refreshSystemInfo() {
  reloadDashboard();
}

/* =============================================================
 * 11. AUTO REFRESH AUTOMÁTICO (POLLING)
 * -------------------------------------------------------------
 * Consulta api/nodes.php cada 8 segundos vía fetch.
 * Solo actualiza la tabla si el estado de los nodos cambió.
 *
 * NOTA: Anteriormente se usaba SSE (Server-Sent Events) con un
 * while(true)+sleep(2) que bloqueaba un worker Apache permanente.
 * En RPi 3B+ con pocos workers esto saturaba el servidor.
 * Se reemplazó por polling ligero que libera el worker tras cada request.
 *
 * Variables de estado:
 *   chilemonPollingInterval  → referencia al setInterval activo
 *   chilemonLastNodeSnapshot → snapshot del último estado conocido
 *
 * Manejo de errores:
 *   - 401 → getJson llama handleUnauthorized() → clearInterval → redirect
 *   - Otros errores de red → console.debug, el intervalo continúa
 * ============================================================= */

/** Polling interval for auto refresh. null = detenido. */
let chilemonPollingInterval = null;

/** Snapshot del último estado de nodos conocido (JSON serializado). */
let chilemonLastNodeSnapshot = "";

/**
 * Genera un snapshot del estado actual de los nodos.
 * Se usa para detectar cambios sin comparar objetos anidados.
 * Si la respuesta no tiene el campo "nodes", retorna "".
 *
 * @param {Object|null} json - Respuesta de api/ami/nodes.php
 * @returns {string} JSON serializado del array de nodos, o "".
 */
function buildNodeSnapshot(json) {
  if (!json || !Array.isArray(json.nodes)) return "";
  return JSON.stringify(json.nodes);
}

/**
 * Detiene el polling de auto refresh.
 * Llamado desde handleUnauthorized() o si se necesita pausar el refresh.
 */
function stopChilemonAutoRefresh() {
  if (chilemonPollingInterval !== null) {
    clearInterval(chilemonPollingInterval);
    chilemonPollingInterval = null;
  }
}

/**
 * Inicia polling ligero para recibir actualizaciones periódicas.
 * Consulta api/nodes.php cada 8 segundos en vez de mantener una
 * conexión SSE permanente que bloqueaba un worker Apache.
 * Optimizado para Raspberry Pi 3B+ con recursos limitados.
 */
function startChilemonAutoRefresh() {
  if (chilemonPollingInterval) return; // ya activo, no duplicar

  chilemonPollingInterval = setInterval(async () => {
    try {
      const json = await getJson("api/nodes.php");
      if (!json || !json.ok) return;

      const nextSnapshot = buildNodeSnapshot(json);

      if (chilemonLastNodeSnapshot === "") {
        chilemonLastNodeSnapshot = nextSnapshot;
        return;
      }

      if (nextSnapshot !== chilemonLastNodeSnapshot) {
        const { changed, nextMap } = detectChangedNodes(
          chilemonPreviousNodeMap,
          json.nodes,
        );

        chilemonLastNodeSnapshot = nextSnapshot;
        renderNodes(json.nodes);

        // Redispara el filtrado de búsqueda si está activo
        const searchInput = document.getElementById("node-search-filter");
        if (searchInput && searchInput.value.length > 0) {
          searchInput.dispatchEvent(new Event("input", { bubbles: true }));
        }

        requestAnimationFrame(() => {
          changed.forEach((item) => {
            markNodeRowAlive(item.nodeId, item.isNew);
          });
        });

        chilemonPreviousNodeMap = nextMap;
      }
    } catch (e) {
      if (e.message === "Unauthorized") {
        handleUnauthorized();
        return;
      }
      console.debug("Polling error:", e.message);
    }
  }, 8000); // 8 segundos — ligero para RPi 3B+
}

/* =============================================================
 * 12. INIT — DOMContentLoaded
 * -------------------------------------------------------------
 * Punto de entrada único del script.
 * Orden de inicialización:
 *   1. Aplicar tema guardado (sincronizar iconos con el estado PHP)
 *   2. Configurar modal de favoritos
 *   3. Consultar snapshot inicial de nodos
 *      - Si 401 → handleUnauthorized() redirige (no se continúa)
 *      - Si error de red → arrancar auto refresh igual (error puntual)
 *   4. Iniciar auto refresh
 * ============================================================= */
document.addEventListener("DOMContentLoaded", async () => {
  // 1. Sincronizar iconos del botón de tema con el atributo data-bs-theme
  //    que PHP ya aplicó en el <html> según la cookie chilemon_darkmode
  const isDark =
    document.documentElement.getAttribute("data-bs-theme") === "dark";
  setThemeUI(isDark);

  // 2. Enlazar todos los eventos del modal de favoritos
  setupFavoritesModal();

  // 3. Obtener snapshot inicial para que el primer tick del auto refresh
  //    tenga una base de comparación y no recargue innecesariamente.
  //    Si la sesión ya expiró aquí, handleUnauthorized() redirige
  //    y el flujo se corta antes de llegar a startChilemonAutoRefresh().
  try {
    const json = await getJson("api/nodes.php");
    chilemonLastNodeSnapshot = buildNodeSnapshot(json);
    chilemonPreviousNodeMap = buildNodeMap(json.nodes || []);
    startChilemonAutoRefresh();
  } catch (e) {
    if (e.message !== "Unauthorized") {
      chilemonLastNodeSnapshot = "";
      startChilemonAutoRefresh();
    }
  }
  // 4. Implementar buscador de nodos en tiempo real (Live Search)
  const searchInput = document.getElementById("node-search-filter");
  const nodesTableBody = document.getElementById("nodes-table-body");
  const totalNodesBadge = document.getElementById("total-nodes-badge");

  if (searchInput && nodesTableBody) {
    searchInput.addEventListener("input", function (e) {
      const searchTerm = e.target.value.toLowerCase().trim();
      const rows = nodesTableBody.querySelectorAll("tr:not(.no-results-row)");

      let visibleCount = 0;

      rows.forEach((row) => {
        // Obtenemos el texto de toda la fila para poder buscar por nodo, alias, IP, etc.
        const rowText = row.textContent.toLowerCase();

        if (rowText.includes(searchTerm)) {
          row.style.display = "";
          visibleCount++;
        } else {
          row.style.display = "none";
        }
      });

      // Manejar el caso de 0 resultados
      let noResultsRow = document.getElementById("no-results-search-row");
      if (visibleCount === 0 && rows.length > 0) {
        if (!noResultsRow) {
          noResultsRow = document.createElement("tr");
          noResultsRow.id = "no-results-search-row";
          noResultsRow.className = "no-results-row text-center py-4 text-muted";
          noResultsRow.innerHTML = `<td colspan="9"><i class="bi bi-search"></i> No se encontraron nodos que coincidan con "${escapeHtml(searchTerm)}"</td>`;
          nodesTableBody.appendChild(noResultsRow);
        } else {
          noResultsRow.innerHTML = `<td colspan="9"><i class="bi bi-search"></i> No se encontraron nodos que coincidan con "${escapeHtml(searchTerm)}"</td>`;
          noResultsRow.style.display = "";
        }
      } else if (noResultsRow) {
        noResultsRow.style.display = "none";
      }

      // Actualizar el contador en el badge superior si existe
      if (totalNodesBadge) {
        if (searchTerm === "") {
          totalNodesBadge.textContent = rows.length;
        } else {
          totalNodesBadge.textContent = visibleCount + " / " + rows.length;
        }
      }
    });
  }
});
