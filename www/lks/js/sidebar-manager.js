/**
 * Единый менеджер для управления sidebar
 */

class SidebarManager {
    constructor() {
        this.isInitialized = false;
        this.sidebar = null;
        this.mainContent = null;
        this.footer = null;
        this.toggleBtn = null;
        this.overlay = null;
        
        // Привязываем методы к контексту
        this.toggle = this.toggle.bind(this);
        this.open = this.open.bind(this);
        this.close = this.close.bind(this);
        this.handleResize = this.handleResize.bind(this);
    }

    init() {
        if (this.isInitialized) {
            return;
        }

        this.sidebar = document.getElementById('sidebar');
        this.mainContent = document.getElementById('mainContent');
        this.footer = document.querySelector('footer#footer');
        this.toggleBtn = document.getElementById('sidebarToggle');
        this.overlay = document.getElementById('sidebarOverlay');

        if (!this.sidebar || !this.toggleBtn) {
            return;
        }

        // Сбрасываем localStorage для отладки
        localStorage.removeItem('sidebarCollapsed');

        this.removeOldHandlers();
        this.addEventListeners();
        
        // НЕ восстанавливаем состояние сразу - оставляем sidebar развернутым по умолчанию
        // this.restoreState();
        
        this.initSubmenus();

        this.isInitialized = true;
        
        // Добавляем глобальную функцию для тестирования
        window.resetSidebar = () => {
            localStorage.removeItem('sidebarCollapsed');
            this.sidebar.classList.remove('collapsed');
            if (this.mainContent) {
                this.mainContent.classList.remove('sidebar-collapsed');
            }
            if (this.footer) {
                this.footer.classList.remove('sidebar-collapsed');
            }
        };
        
        // Функция для проверки состояния
        window.checkSidebar = () => {
            // Функция для отладки состояния sidebar
        };
        
        // Функция для тестирования подменю
        window.testSubmenu = () => {
            // Функция для тестирования подменю
        };
    }

    removeOldHandlers() {
        const newToggleBtn = this.toggleBtn.cloneNode(true);
        this.toggleBtn.parentNode.replaceChild(newToggleBtn, this.toggleBtn);
        this.toggleBtn = newToggleBtn;

        if (this.overlay) {
            const newOverlay = this.overlay.cloneNode(true);
            this.overlay.parentNode.replaceChild(newOverlay, this.overlay);
            this.overlay = newOverlay;
        }
    }

    addEventListeners() {
        this.toggleBtn.addEventListener('click', this.toggle);

        if (this.overlay) {
            this.overlay.addEventListener('click', this.close);
        }

        window.addEventListener('resize', this.handleResize);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.sidebar.classList.contains('show')) {
                this.close();
            }
        });
    }

    toggle() {
        if (window.innerWidth <= 991.98) {
            this.toggleMobile();
        } else {
            this.toggleDesktop();
        }
    }

    toggleMobile() {
        const isOpen = this.sidebar.classList.contains('show');
        
        if (isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    toggleDesktop() {
        const isCollapsed = this.sidebar.classList.contains('collapsed');
        
        if (isCollapsed) {
            this.expand();
        } else {
            this.collapse();
        }
    }

    open() {
        this.sidebar.classList.add('show');
        if (this.overlay) {
            this.overlay.style.display = 'block';
        }
    }

    close() {
        this.sidebar.classList.remove('show');
        if (this.overlay) {
            this.overlay.style.display = 'none';
        }
    }

    expand() {
        this.sidebar.classList.remove('collapsed');
        if (this.mainContent) {
            this.mainContent.classList.remove('sidebar-collapsed');
        }
        if (this.footer) {
            this.footer.classList.remove('sidebar-collapsed');
        }
        localStorage.setItem('sidebarCollapsed', 'false');
    }

    collapse() {
        this.sidebar.classList.add('collapsed');
        if (this.mainContent) {
            this.mainContent.classList.add('sidebar-collapsed');
        }
        if (this.footer) {
            this.footer.classList.add('sidebar-collapsed');
        }
        localStorage.setItem('sidebarCollapsed', 'true');
    }

    restoreState() {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        if (window.innerWidth <= 991.98) {
            // На мобильных устройствах всегда развернутый sidebar
            this.expand();
        } else {
            if (isCollapsed) {
                this.collapse();
            } else {
                this.expand();
            }
        }
    }

    handleResize() {
        if (window.innerWidth > 991.98) {
            // Переключаемся на десктопный режим
            this.sidebar.classList.remove('show');
            if (this.overlay) {
                this.overlay.style.display = 'none';
            }
        }
    }

    initSubmenus() {
        // Инициализация подменю
        const submenuTriggers = this.sidebar.querySelectorAll('.nav-link[href^="#submenu-"]');
        
        submenuTriggers.forEach(trigger => {
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleSubmenu(trigger);
            });
        });
    }

    toggleSubmenu(trigger) {
        const targetId = trigger.getAttribute('href').replace('#', '');
        const submenu = document.getElementById(targetId);
        
        if (!submenu) return;

        const isOpen = submenu.style.display === 'block';
        
        if (isOpen) {
            submenu.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');
            trigger.classList.remove('active');
        } else {
            // Закрываем другие подменю
            this.sidebar.querySelectorAll('.submenu').forEach(menu => {
                if (menu !== submenu) {
                    menu.style.display = 'none';
                }
            });
            
            // Деактивируем другие триггеры
            this.sidebar.querySelectorAll('[href^="#submenu-"]').forEach(t => {
                if (t !== trigger) {
                    t.setAttribute('aria-expanded', 'false');
                    t.classList.remove('active');
                }
            });

            submenu.style.display = 'block';
            trigger.setAttribute('aria-expanded', 'true');
            trigger.classList.add('active');
        }
    }

    toggleSuperuserSubmenu(trigger) {
        const targetId = trigger.getAttribute('href').replace('#', '');
        const submenu = document.getElementById(targetId);
        
        if (!submenu) return;

        const isOpen = submenu.style.display === 'block';
        
        if (isOpen) {
            submenu.style.display = 'none';
            trigger.setAttribute('aria-expanded', 'false');
            trigger.classList.remove('active');
        } else {
            // Закрываем все другие подменю
            document.querySelectorAll('.submenu').forEach(menu => {
                if (menu !== submenu) {
                    menu.style.display = 'none';
                }
            });
            
            // Деактивируем все другие триггеры
            document.querySelectorAll('[href^="#submenu-"]').forEach(t => {
                if (t !== trigger) {
                    t.setAttribute('aria-expanded', 'false');
                    t.classList.remove('active');
                }
            });

            submenu.style.display = 'block';
            trigger.setAttribute('aria-expanded', 'true');
            trigger.classList.add('active');
        }
    }

    destroy() {
        if (this.toggleBtn) {
            this.toggleBtn.removeEventListener('click', this.toggle);
        }
        if (this.overlay) {
            this.overlay.removeEventListener('click', this.close);
        }
        window.removeEventListener('resize', this.handleResize);
        
        this.isInitialized = false;
    }
}

// Создаем глобальный экземпляр
window.sidebarManager = new SidebarManager();

// Инициализируем после загрузки DOM
document.addEventListener('DOMContentLoaded', () => {
    window.sidebarManager.init();
});
