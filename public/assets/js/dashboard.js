const base = window.CHILEMON_BASE || "./";

// ejemplo:
fetch(base + "api/nodes.php", {
  /* ... */
});

function setThemeUI(isDark) {
  // Actualiza TODOS los iconos dentro de botones/links que usen Bootstrap Icons
  document.querySelectorAll("[data-theme-icon] i").forEach((icon) => {
    icon.classList.remove("bi-sun", "bi-moon-stars");
    icon.classList.add(isDark ? "bi-sun" : "bi-moon-stars");
  });

  // Texto opcional (si usas data-theme-text)
  document.querySelectorAll("[data-theme-text]").forEach((el) => {
    el.textContent = isDark ? "Tema claro" : "Tema oscuro";
  });

  // Title opcional (si usas data-theme-title)
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

// Al cargar: sincroniza UI con el tema real del HTML
document.addEventListener("DOMContentLoaded", () => {
  const isDark =
    document.documentElement.getAttribute("data-bs-theme") === "dark";
  setThemeUI(isDark);
});
