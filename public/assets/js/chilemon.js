// assets/js/chilemon.js - Funciones principales de ChileMon

// Inicialización cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('🇨🇱 ChileMon - MySQL Edition');
    
    // Inicializar componentes
    initTimeUpdater();
    initTooltips();
    initNodeButtons();
    
    // Mostrar versión en consola
    const version = document.querySelector('.navbar-brand .badge')?.textContent || '0.3.0';
    console.log(`Versión: ${version}`);
});

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

// El tema vive en dashboard.js (ThemeManager); este archivo no gestiona cookies.