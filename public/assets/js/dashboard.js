const base = window.CHILEMON_BASE || "./";

// -------------------------
// CSRF helpers
// -------------------------
function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute("content") || "" : "";
}

async function postForm(url, dataObj) {
  const form = new URLSearchParams();
  Object.entries(dataObj).forEach(([k, v]) => form.append(k, v));

  // incluir CSRF siempre (si está)
  const csrf = getCsrfToken();
  if (csrf) form.append("csrf_token", csrf);

  const res = await fetch(base + url, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
    },
    body: form.toString(),
    credentials: "same-origin",
  });

  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    // Si no es JSON, devolvemos error genérico
  }

  if (!res.ok) {
    const msg =
      json && (json.error || json.message)
        ? json.error || json.message
        : `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return json;
}

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
    const msg =
      json && (json.error || json.message)
        ? json.error || json.message
        : `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return json;
}

// -------------------------
// Tema (tu código intacto)
// -------------------------
function setThemeUI(isDark) {
  document.querySelectorAll("[data-theme-icon] i").forEach((icon) => {
    icon.classList.remove("bi-sun", "bi-moon-stars");
    icon.classList.add(isDark ? "bi-sun" : "bi-moon-stars");
  });

  document.querySelectorAll("[data-theme-text]").forEach((el) => {
    el.textContent = isDark ? "Tema claro" : "Tema oscuro";
  });

  document.querySelectorAll("[data-theme-title]").forEach((el) => {
    el.setAttribute("title", isDark ? "Tema claro" : "Tema oscuro");
  });
}

function toggleTheme() {
  const html = document.documentElement;
  const isDark = html.getAttribute("data-bs-theme") === "dark";
  const nextDark = !isDark;

  html.setAttribute("data-bs-theme", nextDark ? "dark" : "light");
  document.cookie = `chilemon_darkmode=${nextDark}; path=/; max-age=31536000`;

  setThemeUI(nextDark);
}

document.addEventListener("DOMContentLoaded", () => {
  const isDark =
    document.documentElement.getAttribute("data-bs-theme") === "dark";
  setThemeUI(isDark);

  // Wireup favoritos (si existe el modal en esta vista)
  setupFavoritesModal();
});

// -------------------------
// Acciones: Connect / Disconnect
// -------------------------
async function connectToNode() {
  const input = document.getElementById("node-number");
  const node = input ? (input.value || "").trim() : "";
  if (!node) {
    alert("Ingresa un número de nodo.");
    return;
  }

  try {
    await postForm("api/connect.php", { node });
    alert(`Conectado a ${node}`);
    // Si quieres: auto refresh
    // location.reload();
  } catch (e) {
    alert(`Error al conectar: ${e.message}`);
  }
}

async function connectToSpecificNode(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  try {
    await postForm("api/connect.php", { node });
    alert(`Conectado a ${node}`);
    // location.reload();
  } catch (e) {
    alert(`Error al conectar: ${e.message}`);
  }
}

async function disconnectFromNode(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  try {
    await postForm("api/disconnect.php", { node });
    alert(`Desconectado de ${node}`);
    // location.reload();
  } catch (e) {
    alert(`Error al desconectar: ${e.message}`);
  }
}

/**
 * ✅ Esta faltaba: tu dashboard.php llama disconnectFromNodeConfirm()
 * Si no existe, te queda el modal/alert “raro” o error JS silencioso.
 */
function disconnectFromNodeConfirm(nodeId) {
  const node = (nodeId || "").toString().trim();
  if (!node) return;

  const ok = confirm(`¿Seguro que deseas desconectar el nodo ${node}?`);
  if (!ok) return;

  disconnectFromNode(node);
}

// -------------------------
// Favoritos: Modal + CRUD + conectar desde favorito
// Requiere:
// - Modal con id favoritesModal
// - Form id favForm y tbody id fav_tbody
// - Botones: #fav_reload, #fav_clear
// - Endpoint: api/favorites.php
// -------------------------
function setupFavoritesModal() {
  const modalEl = document.getElementById("favoritesModal");
  if (!modalEl) return;

  // Cargar al abrir
  modalEl.addEventListener("show.bs.modal", () => {
    favoritesReload();
  });

  // Reload manual
  const btnReload = document.getElementById("fav_reload");
  if (btnReload) {
    btnReload.addEventListener("click", (e) => {
      e.preventDefault();
      favoritesReload();
    });
  }

  // Clear
  const btnClear = document.getElementById("fav_clear");
  if (btnClear) {
    btnClear.addEventListener("click", (e) => {
      e.preventDefault();
      favoritesClearForm();
    });
  }

  // Submit upsert
  const form = document.getElementById("favForm");
  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();

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
        await postForm("api/favorites.php", {
          action: "upsert",
          node_id,
          alias,
          description,
        });
        favoritesClearForm();
        await favoritesReload();
      } catch (err) {
        alert(`Error guardando favorito: ${err.message}`);
      }
    });
  }
}

function favoritesClearForm() {
  const a = document.getElementById("fav_node_id");
  const b = document.getElementById("fav_alias");
  const c = document.getElementById("fav_desc");
  if (a) a.value = "";
  if (b) b.value = "";
  if (c) c.value = "";
}

async function favoritesReload() {
  const tbody = document.getElementById("fav_tbody");
  if (!tbody) return;

  tbody.innerHTML = `<tr><td colspan="4" class="text-muted">Cargando...</td></tr>`;

  try {
    const json = await getJson("api/favorites.php");
    const items = json && json.items ? json.items : [];
    renderFavorites(items);
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-danger">Error: ${escapeHtml(err.message)}</td></tr>`;
  }
}

function renderFavorites(items) {
  const tbody = document.getElementById("fav_tbody");
  if (!tbody) return;

  if (!items || items.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-muted">Sin favoritos aún.</td></tr>`;
    return;
  }

  tbody.innerHTML = items
    .map((it) => {
      const node = escapeHtml((it.node_id || "").toString());
      const alias = escapeHtml((it.alias || "").toString());
      const desc = escapeHtml((it.description || "").toString());

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

  // Wire buttons
  tbody.querySelectorAll("[data-fav-connect]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const node = btn.getAttribute("data-fav-connect") || "";
      const ok = confirm(`¿Conectar al nodo ${node}?`);
      if (!ok) return;

      try {
        await postForm("api/connect.php", { node });
        // Cierra modal y vuelve “conectado”
        const modalEl = document.getElementById("favoritesModal");
        if (modalEl && window.bootstrap) {
          const inst = window.bootstrap.Modal.getInstance(modalEl);
          if (inst) inst.hide();
        }
        alert(`Conectado a ${node}`);
        // Si quieres refrescar tabla automáticamente:
        // location.reload();
      } catch (err) {
        alert(`Error al conectar: ${err.message}`);
      }
    });
  });

  tbody.querySelectorAll("[data-fav-delete]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const node = btn.getAttribute("data-fav-delete") || "";
      const ok = confirm(`¿Eliminar favorito ${node}?`);
      if (!ok) return;

      try {
        await postForm("api/favorites.php", {
          action: "delete",
          node_id: node,
        });
        await favoritesReload();
      } catch (err) {
        alert(`Error eliminando favorito: ${err.message}`);
      }
    });
  });
}

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
