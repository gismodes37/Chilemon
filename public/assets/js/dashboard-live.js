/**
 * ChileMon — Dashboard Live
 * Polling de endpoints AMI para refrescar UI sin recargar.
 */

const base = window.CHILEMON_BASE || "./";

/* ---------------------------------------
 * Helpers: fetch JSON (con cookies)
 * ------------------------------------- */
async function getJson(url) {
  const res = await fetch(base + url, {
    method: "GET",
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });

  let json = null;
  try {
    json = await res.json();
  } catch (e) {}

  if (!res.ok) {
    const msg = json?.error || json?.message || `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return json;
}

/* ---------------------------------------
 * UI helpers: set text seguro
 * ------------------------------------- */
function setText(selector, value) {
  const el = document.querySelector(selector);
  if (el) el.textContent = value ?? "";
}

/* ---------------------------------------
 * Render: lista de nodos conectados
 * Espera un <tbody id="nodes_tbody"> o un contenedor.
 * ------------------------------------- */
function renderNodes(nodes) {
  // Ajusta estos selectores a tu HTML real
  setText("#nodes_count", String(nodes.length));

  const tbody = document.getElementById("nodes_tbody");
  if (!tbody) return;

  if (!nodes.length) {
    tbody.innerHTML = `<tr><td class="text-muted">Sin nodos conectados</td></tr>`;
    return;
  }

  tbody.innerHTML = nodes
    .map((n) => `<tr><td class="fw-semibold">${escapeHtml(n)}</td></tr>`)
    .join("");
}

/* ---------------------------------------
 * Render: actividad reciente (tabla)
 * Espera <tbody id="activity_tbody">
 * ------------------------------------- */
function renderActivity(items) {
  const tbody = document.getElementById("activity_tbody");
  if (!tbody) return;

  if (!items || items.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="4" class="text-center py-3 text-muted">
          <i class="bi bi-info-circle"></i> Aún no hay actividad registrada.
        </td>
      </tr>`;
    return;
  }

  tbody.innerHTML = items
    .map((ev) => {
      const createdAt = escapeHtml(ev.created_at || "");
      const nodeNum = escapeHtml(ev.node_number || "");
      const type = escapeHtml(ev.event_type || "");
      const details = escapeHtml(ev.details || "");

      const badge =
        type === "connect"
          ? "bg-success"
          : type === "disconnect"
            ? "bg-danger"
            : type.startsWith("favorite")
              ? "bg-warning"
              : "bg-secondary";

      return `
      <tr>
        <td class="text-muted">${createdAt}</td>
        <td>${nodeNum}</td>
        <td><span class="badge ${badge}">${type}</span></td>
        <td>${details}</td>
      </tr>
    `;
    })
    .join("");
}

/* ---------------------------------------
 * Seguridad: escape HTML básico
 * ------------------------------------- */
function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

/* ---------------------------------------
 * Polling: nodos conectados
 * ------------------------------------- */
async function pollNodes() {
  const json = await getJson("api/ami/nodes.php");
  const nodes = json.nodes || json?.data?.nodes || [];
  renderNodes(nodes);
}

/* ---------------------------------------
 * Polling: actividad reciente
 * ------------------------------------- */
async function pollActivity() {
  const json = await getJson("api/ami/activity.php?limit=15");
  renderActivity(json.items || []);
}

/* ---------------------------------------
 * Scheduler: intervalos
 * ------------------------------------- */
function startLive() {
  // Primer refresh inmediato
  pollNodes().catch(console.error);
  pollActivity().catch(console.error);

  // Intervalos (ajustables)
  setInterval(() => pollNodes().catch(console.error), 5000);
  setInterval(() => pollActivity().catch(console.error), 8000);
}

document.addEventListener("DOMContentLoaded", startLive);
