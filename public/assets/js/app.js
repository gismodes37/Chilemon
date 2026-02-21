/**
 * ChileMon - Aplicación principal
 * Versión: 0.1.0
 */
class ChileMon {
    constructor() {
        this.version = '0.1.0';
        this.apiBase = '/Chilemon/public/api/';
        this.nodes = [];
        this.init();
    }
    
    init() {
        console.log(`%c🇨🇱 ChileMon v${this.version}`, 'color: #0039A6; font-size: 16px; font-weight: bold;');
        console.log('Dashboard para ASL# inicializado');
        
        // Cargar datos iniciales
        this.loadSampleData();
        
        // Configurar eventos
        this.setupEventListeners();
        
        // Actualizar hora cada minuto
        this.updateTime();
        setInterval(() => this.updateTime(), 60000);
    }
    
    loadSampleData() {
        // Datos de ejemplo para desarrollo
        this.nodes = [
            {
                id: 'CL-SCL-1',
                name: 'Santiago Centro',
                frequency: '145.350',
                mode: 'DMR',
                users: 8,
                status: 'online',
                lastUpdate: new Date().toISOString()
            },
            {
                id: 'CL-VAP-1',
                name: 'Valparaíso Puerto',
                frequency: '433.450',
                mode: 'YSF',
                users: 5,
                status: 'online',
                lastUpdate: new Date(Date.now() - 300000).toISOString() // 5 minutos atrás
            },
            {
                id: 'CL-CON-1',
                name: 'Concepción',
                frequency: '145.750',
                mode: 'P25',
                users: 0,
                status: 'offline',
                lastUpdate: new Date(Date.now() - 3600000).toISOString() // 1 hora atrás
            }
        ];
        
        this.updateNodeDisplay();
    }
    
    updateNodeDisplay() {
        const container = document.getElementById('node-container');
        if (!container) return;
        
        container.innerHTML = this.nodes.map(node => `
            <div class="col-md-4 mb-3">
                <div class="card h-100 border-${node.status === 'online' ? 'success' : 'danger'} border-2">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>${node.name}</strong>
                        <span class="badge bg-${node.status === 'online' ? 'success' : 'danger'}">
                            ${node.status === 'online' ? 'ONLINE' : 'OFFLINE'}
                        </span>
                    </div>
                    <div class="card-body">
                        <p><i class="bi bi-hash"></i> <strong>ID:</strong> ${node.id}</p>
                        <p><i class="bi bi-radioactive"></i> <strong>Frecuencia:</strong> ${node.frequency} MHz</p>
                        <p><i class="bi bi-wifi"></i> <strong>Modo:</strong> ${node.mode}</p>
                        <p><i class="bi bi-people"></i> <strong>Usuarios:</strong> ${node.users}</p>
                        <p class="text-muted small">
                            <i class="bi bi-clock"></i> 
                            Actualizado: ${new Date(node.lastUpdate).toLocaleTimeString('es-CL')}
                        </p>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-telephone"></i> Llamar via IAX
                        </button>
                        <button class="btn btn-sm btn-outline-info ms-1">
                            <i class="bi bi-info-circle"></i> Detalles
                        </button>
                    </div>
                </div>
            </div>
        `).join('');
        
        // Actualizar estadísticas
        this.updateStats();
    }
    
    updateStats() {
        const totalNodes = this.nodes.length;
        const onlineNodes = this.nodes.filter(n => n.status === 'online').length;
        const totalUsers = this.nodes.reduce((sum, node) => sum + node.users, 0);
        
        console.log(`📊 Estadísticas: ${onlineNodes}/${totalNodes} nodos online, ${totalUsers} usuarios`);
        
        // Actualizar UI si existen los elementos
        const statsElement = document.getElementById('stats');
        if (statsElement) {
            statsElement.innerHTML = `
                <span class="badge bg-primary">${totalNodes} Nodos</span>
                <span class="badge bg-success ms-1">${onlineNodes} Online</span>
                <span class="badge bg-info ms-1">${totalUsers} Usuarios</span>
            `;
        }
    }
    
    updateTime() {
        const timeElement = document.getElementById('current-time');
        if (timeElement) {
            const now = new Date();
            timeElement.textContent = now.toLocaleTimeString('es-CL', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }
    }
    
    setupEventListeners() {
        console.log('Event listeners configurados');
        
        // Ejemplo: Botón de prueba
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('btn-outline-primary')) {
                alert('🚧 Función de llamada IAX en desarrollo');
            }
        });
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    window.chilemonApp = new ChileMon();
});