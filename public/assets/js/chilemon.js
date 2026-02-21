// assets/js/chilemon.js - Funciones principales de ChileMon

// Inicialización cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('🇨🇱 ChileMon - MySQL Edition');
    
    // Inicializar componentes
    initTheme();
    initTimeUpdater();
    initTooltips();
    initNodeButtons();
    
    // Mostrar versión en consola
    const version = document.querySelector('.navbar-brand .badge')?.textContent || '0.3.0';
    console.log(`Versión: ${version}`);
});

// 1. Gestión de temas
function initTheme() {
    // Cargar tema guardado
    const savedTheme = getCookie('chilemon_darkmode');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    let initialTheme = 'light';
    if (savedTheme === 'true') {
        initialTheme = 'dark';
    } else if (savedTheme === null && prefersDark) {
        initialTheme = 'dark';
    }
    
    // Aplicar tema inicial
    document.documentElement.setAttribute('data-bs-theme', initialTheme);
    updateThemeIcons(initialTheme);
    
    console.log(`Tema inicial: ${initialTheme}`);
}

function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-bs-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    console.log(`Cambiando tema: ${currentTheme} → ${newTheme}`);
    
    // Aplicar nuevo tema
    html.setAttribute('data-bs-theme', newTheme);
    
    // Guardar preferencia
    setCookie('chilemon_darkmode', newTheme === 'dark', 30);
    
    // Actualizar iconos
    updateThemeIcons(newTheme);
    
    // Feedback visual
    showThemeChangeFeedback(newTheme);
    
    return false; // Prevenir comportamiento por defecto
}

function updateThemeIcons(theme) {
    // Botón flotante
    const toggleBtn = document.querySelector('.theme-toggle-btn');
    if (toggleBtn) {
        const icon = toggleBtn.querySelector('i');
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
        }
        toggleBtn.title = theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro';
    }
    
    // Enlace en footer
    const themeLinks = document.querySelectorAll('a[onclick*="toggleTheme"]');
    themeLinks.forEach(link => {
        const icon = link.querySelector('i');
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun me-1' : 'bi bi-moon-stars me-1';
        }
        link.innerHTML = theme === 'dark' ? 
            '<i class="bi bi-sun me-1"></i>Tema claro' : 
            '<i class="bi bi-moon-stars me-1"></i>Tema oscuro';
    });
}

function showThemeChangeFeedback(theme) {
    // Animación en el botón
    const toggleBtn = document.querySelector('.theme-toggle-btn');
    if (toggleBtn) {
        toggleBtn.style.transform = 'scale(1.2)';
        setTimeout(() => {
            toggleBtn.style.transform = 'scale(1)';
        }, 200);
    }
    
    console.log(`✅ Tema cambiado a: ${theme === 'dark' ? 'oscuro 🌙' : 'claro ☀️'}`);
}

// 2. Actualización de hora
function initTimeUpdater() {
    updateTime();
    setInterval(updateTime, 1000);
}

function updateTime() {
    const now = new Date();
    
    // Hora simple (header)
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        timeElement.textContent = now.toLocaleTimeString('es-CL', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    // Hora completa (sistema)
    const liveTimeElement = document.getElementById('live-time');
    if (liveTimeElement) {
        liveTimeElement.textContent = now.toLocaleTimeString('es-CL');
    }
    
    // Fecha completa (footer)
    const dateElement = document.getElementById('full-date');
    if (dateElement) {
        dateElement.textContent = now.toLocaleDateString('es-CL', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
}

// 3. Tooltips de Bootstrap
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// 4. Botones de nodos
function initNodeButtons() {
    // Botones de llamada IAX
    document.querySelectorAll('.btn-outline-primary').forEach(button => {
        button.addEventListener('click', function(e) {
            if (this.onclick) return; // Si ya tiene onclick, no hacer nada
            
            const nodeId = this.closest('.card-node')?.querySelector('.node-id')?.textContent || 'Nodo';
            alert(`Llamada IAX a ${nodeId}\nEsta funcionalidad está en desarrollo.`);
        });
    });
    
    // Botones de detalles
    document.querySelectorAll('.btn-outline-secondary').forEach(button => {
        button.addEventListener('click', function(e) {
            if (this.onclick) return;
            
            const nodeId = this.closest('.card-node')?.querySelector('.node-id')?.textContent || 'Nodo';
            alert(`Detalles del nodo: ${nodeId}\nInformación detallada en desarrollo.`);
        });
    });
}

// 5. Funciones de utilidad
function makeCall(nodeId) {
    alert(`Llamada IAX a ${nodeId}\nEsta funcionalidad está en desarrollo.`);
}

function showDetails(nodeId) {
    alert(`Detalles del nodo: ${nodeId}\nInformación detallada en desarrollo.`);
}

// 6. Funciones de cookies
function setCookie(name, value, days) {
    const d = new Date();
    d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = "expires=" + d.toUTCString();
    document.cookie = name + "=" + value + ";" + expires + ";path=/;SameSite=Lax";
}

function getCookie(name) {
    const nameEQ = name + "=";
    const ca = document.cookie.split(';');
    for(let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
}