/**
 * Единый менеджер для управления sidebar
 * Исправленная версия для устранения конфликтов
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
        
        console.log('SidebarManager: Конструктор создан');
    }

    init() {
        if (this.isInitialized) {
            console.log('SidebarManager уже инициализирован');
            return;
        }

        console.log('SidebarManager: Начало инициализации');

        // Ждем полной загрузки DOM
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.initElements());
        } else {
            this.initElements();
        }
    }

    initElements() {
        console.log('SidebarManager: Поиск элементов...');
        
        this.sidebar = document.getElementById('sidebar');
        this.mainContent = document.getElementById('mainContent');
        this.footer = document.querySelector('footer#footer');
        this.toggleBtn = document.getElementById('sidebarToggle');
        this.overlay = document.getElementById('sidebarOverlay');

        console.log('SidebarManager: Найденные элементы:', {
            sidebar: !!this.sidebar,
            mainContent: !!this.mainContent,
            footer: !!this.footer,
            toggleBtn: !!this.toggleBtn,
            overlay: !!this.overlay
        });

        if (!this.sidebar || !this.toggleBtn) {
            console.warn('SidebarManager: Не найдены необходимые элементы sidebar или toggleBtn');
            console.warn('SidebarManager: sidebar =', this.sidebar);
            console.warn('SidebarManager: toggleBtn =', this.toggleBtn);
            return;
        }

        // Очищаем старые обработчики
        this.removeOldHandlers();
        
        // Добавляем новые обработчики
        this.addEventListeners();
        
        // Инициализируем подменю
        this.initSubmenus();

        this.isInitialized = true;
        console.log('SidebarManager успешно инициализирован');
        
        // Добавляем глобальные функции для отладки
        this.addDebugFunctions();
        
        // Проверяем начальное состояние
        this.checkInitialState();
    }

    checkInitialState() {
        console.log('SidebarManager: Проверка начального состояния');
        
        if (this.sidebar) {
            console.log('Sidebar классы:', this.sidebar.className);
            console.log('Sidebar collapsed:', this.sidebar.classList.contains('collapsed'));
            console.log('Sidebar show:', this.sidebar.classList.contains('show'));
        }
        
        if (this.mainContent) {
            console.log('MainContent sidebar-collapsed:', this.mainContent.classList.contains('sidebar-collapsed'));
        }
        
        if (this.footer) {
            console.log('Footer sidebar-collapsed:', this.footer.classList.contains('sidebar-collapsed'));
        }
    }

    removeOldHandlers() {
        console.log('SidebarManager: Удаление старых обработчиков');
        
        // Создаем новые элементы для очистки обработчиков
        if (this.toggleBtn) {
            const newToggleBtn = this.toggleBtn.cloneNode(true);
            this.toggleBtn.parentNode.replaceChild(newToggleBtn, this.toggleBtn);
            this.toggleBtn = newToggleBtn;
            console.log('SidebarManager: ToggleBtn заменен');
        }

        if (this.overlay) {
            const newOverlay = this.overlay.cloneNode(true);
            this.overlay.parentNode.replaceChild(newOverlay, this.overlay);
            this.overlay = newOverlay;
            console.log('SidebarManager: Overlay заменен');
        }
    }

    addEventListeners() {
        console.log('SidebarManager: Добавление обработчиков событий');
        
        if (this.toggleBtn) {
            this.toggleBtn.addEventListener('click', this.toggle);
            console.log('SidebarManager: Обработчик toggle добавлен');
        }

        if (this.overlay) {
            this.overlay.addEventListener('click', this.close);
            console.log('SidebarManager: Обработчик overlay добавлен');
        }

        // Обработчик изменения размера окна
        window.addEventListener('resize', this.handleResize);
        console.log('SidebarManager: Обработчик resize добавлен');

        // Обработчик клавиши Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.sidebar && this.sidebar.classList.contains('show')) {
                this.close();
            }
        });
        console.log('SidebarManager: Обработчик Escape добавлен');
    }

    toggle() {
        console.log('SidebarManager: toggle() вызван');
        
        if (window.innerWidth <= 991.98) {
            console.log('SidebarManager: Переключение в мобильном режиме');
            this.toggleMobile();
        } else {
            console.log('SidebarManager: Переключение в десктопном режиме');
            this.toggleDesktop();
        }
    }

    toggleMobile() {
        if (!this.sidebar) {
            console.error('SidebarManager: sidebar не найден в toggleMobile');
            return;
        }
        
        const isOpen = this.sidebar.classList.contains('show');
        console.log('SidebarManager: toggleMobile, isOpen =', isOpen);
        
        if (isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    toggleDesktop() {
        if (!this.sidebar) {
            console.error('SidebarManager: sidebar не найден в toggleDesktop');
            return;
        }
        
        const isCollapsed = this.sidebar.classList.contains('collapsed');
        console.log('SidebarManager: toggleDesktop, isCollapsed =', isCollapsed);
        
        if (isCollapsed) {
            this.expand();
        } else {
            this.collapse();
        }
    }

    open() {
        if (!this.sidebar) {
            console.error('SidebarManager: sidebar не найден в open');
            return;
        }
        
        console.log('SidebarManager: Открытие sidebar');
        this.sidebar.classList.add('show');
        if (this.overlay) {
            this.overlay.style.display = 'block';
        }
    }

    close() {
        if (!this.sidebar) {
            console.error('SidebarManager: sidebar не найден в close');
            return;
        }
        
        console.log('SidebarManager: Закрытие sidebar');
        this.sidebar.classList.remove('show');
        if (this.overlay) {
            this.overlay.style.display = 'none';
        }
    }

    expand() {
        if (!this.sidebar) {
            console.error('SidebarManager: sidebar не найден в expand');
            return;
        }
        
        console.log('SidebarManager: Разворачивание sidebar');
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
        if (!this.sidebar) {
            console.error('SidebarManager: sidebar не найден в collapse');
            return;
        }
        
        console.log('SidebarManager: Сворачивание sidebar');
        this.sidebar.classList.add('collapsed');
        if (this.mainContent) {
            this.mainContent.classList.add('sidebar-collapsed');
        }
        if (this.footer) {
            this.footer.classList.add('sidebar-collapsed');
        }
        localStorage.setItem('sidebarCollapsed', 'true');
    }

    handleResize() {
        console.log('SidebarManager: Обработка изменения размера окна');
        
        if (window.innerWidth > 991.98) {
            // Переключаемся на десктопный режим
            if (this.sidebar) {
                this.sidebar.classList.remove('show');
            }
            if (this.overlay) {
                this.overlay.style.display = 'none';
            }
        }
    }

    initSubmenus() {
        if (!this.sidebar) {
            console.error('SidebarManager: sidebar не найден в initSubmenus');
            return;
        }
        
        console.log('SidebarManager: Инициализация подменю');
        
        // Инициализация подменю
        const submenuTriggers = this.sidebar.querySelectorAll('.nav-link[href^="#submenu-"]');
        console.log('SidebarManager: Найдено триггеров подменю:', submenuTriggers.length);
        
        submenuTriggers.forEach((trigger, index) => {
            console.log(`SidebarManager: Обработка триггера ${index + 1}:`, trigger.textContent.trim());
            
            // Удаляем старые обработчики
            const newTrigger = trigger.cloneNode(true);
            trigger.parentNode.replaceChild(newTrigger, trigger);
            
            // Добавляем новый обработчик
            newTrigger.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                console.log('SidebarManager: Клик по триггеру подменю:', newTrigger.textContent.trim());
                this.toggleSubmenu(newTrigger);
            });
            
            console.log(`SidebarManager: Обработчик добавлен для триггера ${index + 1}`);
        });
        
        // Проверяем начальное состояние подменю
        const submenus = this.sidebar.querySelectorAll('.submenu');
        console.log('SidebarManager: Найдено подменю:', submenus.length);
        
        submenus.forEach((submenu, index) => {
            const trigger = this.sidebar.querySelector(`[href="#${submenu.id}"]`);
            const isExpanded = trigger && trigger.getAttribute('aria-expanded') === 'true';
            
            console.log(`SidebarManager: Подменю ${index + 1}:`, submenu.id, 'aria-expanded =', isExpanded);
            
            // Устанавливаем правильное начальное состояние
            if (isExpanded) {
                submenu.style.setProperty('display', 'block', 'important');
                console.log(`SidebarManager: Подменю ${index + 1} установлено в 'block' (активное)`);
            } else {
                submenu.style.setProperty('display', 'none', 'important');
                console.log(`SidebarManager: Подменю ${index + 1} установлено в 'none' (неактивное)`);
            }
            
            // Добавляем обработчики для пунктов в подменю
            const submenuItems = submenu.querySelectorAll('.nav-link');
            submenuItems.forEach(item => {
                item.addEventListener('click', (e) => {
                    // Если пункт активен, не закрываем подменю
                    if (item.classList.contains('active')) {
                        console.log('SidebarManager: Клик по активному пункту в подменю, подменю остается открытым');
                        e.preventDefault();
                        return false;
                    }
                });
            });
        });
        
        // Убираем все глобальные обработчики, которые могут мешать Bootstrap
        // Они не нужны для работы sidebar
    }

    toggleSubmenu(trigger) {
        const targetId = trigger.getAttribute('href').replace('#', '');
        const submenu = document.getElementById(targetId);
        
        console.log('SidebarManager: toggleSubmenu вызван для:', targetId);
        
        if (!submenu) {
            console.error('SidebarManager: Подменю не найдено:', targetId);
            return;
        }

        // Проверяем состояние sidebar
        const sidebarCollapsed = this.sidebar && this.sidebar.classList.contains('collapsed');
        console.log('SidebarManager: sidebar collapsed =', sidebarCollapsed);
        
        // Убираем блокировку для свернутого сайдбара - теперь можно открывать подменю
        // if (sidebarCollapsed) {
        //     console.warn('SidebarManager: Sidebar свернут, подменю не может быть открыто из-за CSS правила');
        //     return;
        // }

        // Проверяем состояние подменю через aria-expanded
        const isExpanded = trigger.getAttribute('aria-expanded') === 'true';
        const isOpen = isExpanded;
        
        console.log('SidebarManager: toggleSubmenu, aria-expanded =', isExpanded, 'isOpen =', isOpen);
        
        if (isOpen) {
            // Проверяем, есть ли активный пункт в подменю
            const activeItem = submenu.querySelector('.nav-link.active');
            if (activeItem) {
                console.log('SidebarManager: В подменю есть активный пункт, подменю остается открытым');
                return; // Не закрываем подменю, если в нем есть активный пункт
            }
            
            // Подменю открыто - закрываем
            submenu.style.setProperty('display', 'none', 'important');
            trigger.setAttribute('aria-expanded', 'false');
            console.log('SidebarManager: Подменю закрыто');
        } else {
            // Подменю закрыто - открываем
            submenu.style.setProperty('display', 'block', 'important');
            trigger.setAttribute('aria-expanded', 'true');
            console.log('SidebarManager: Подменю открыто');
        }
    }

    addDebugFunctions() {
        console.log('SidebarManager: Добавление отладочных функций');
        
        // Функция для сброса состояния sidebar
        window.resetSidebar = () => {
            console.log('SidebarManager: Сброс sidebar');
            localStorage.removeItem('sidebarCollapsed');
            if (this.sidebar) {
                this.sidebar.classList.remove('collapsed');
                this.sidebar.classList.remove('show');
            }
            if (this.mainContent) {
                this.mainContent.classList.remove('sidebar-collapsed');
            }
            if (this.footer) {
                this.footer.classList.remove('sidebar-collapsed');
            }
            if (this.overlay) {
                this.overlay.style.display = 'none';
            }
            console.log('Sidebar сброшен в исходное состояние');
        };
        
        // Функция для сброса состояния подменю
        window.resetSubmenus = () => {
            console.log('SidebarManager: Сброс подменю');
            if (this.sidebar) {
                const submenus = this.sidebar.querySelectorAll('.submenu');
                submenus.forEach(submenu => {
                    submenu.style.display = 'none';
                });
                
                const triggers = this.sidebar.querySelectorAll('[href^="#submenu-"]');
                triggers.forEach(trigger => {
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.classList.remove('active');
                });
                
                console.log('Все подменю закрыты');
            }
        };
        
        // Функция для проверки состояния sidebar
        window.checkSidebar = () => {
            console.log('SidebarManager: Проверка состояния sidebar');
            console.log('Состояние sidebar:', {
                sidebar: this.sidebar ? {
                    collapsed: this.sidebar.classList.contains('collapsed'),
                    show: this.sidebar.classList.contains('show')
                } : 'не найден',
                mainContent: this.mainContent ? {
                    sidebarCollapsed: this.mainContent.classList.contains('sidebar-collapsed')
                } : 'не найден',
                footer: this.footer ? {
                    sidebarCollapsed: this.footer.classList.contains('sidebar-collapsed')
                } : 'не найден',
                overlay: this.overlay ? {
                    display: this.overlay.style.display
                } : 'не найден'
            });
        };
        
        // Функция для принудительного переключения
        window.forceToggleSidebar = () => {
            console.log('SidebarManager: Принудительное переключение sidebar');
            this.toggle();
        };
        
        // Функция для принудительного разворачивания sidebar
        window.expandSidebar = () => {
            console.log('SidebarManager: Принудительное разворачивание sidebar');
            if (this.sidebar) {
                this.sidebar.classList.remove('collapsed');
                if (this.mainContent) {
                    this.mainContent.classList.remove('sidebar-collapsed');
                }
                if (this.footer) {
                    this.footer.classList.remove('sidebar-collapsed');
                }
                console.log('Sidebar развернут');
            }
        };
    }

    destroy() {
        console.log('SidebarManager: Уничтожение');
        
        if (this.toggleBtn) {
            this.toggleBtn.removeEventListener('click', this.toggle);
        }
        if (this.overlay) {
            this.overlay.removeEventListener('click', this.close);
        }
        window.removeEventListener('resize', this.handleResize);
        
        this.isInitialized = false;
        console.log('SidebarManager уничтожен');
    }
}

// Создаем глобальный экземпляр
window.sidebarManager = new SidebarManager();

// Инициализируем после загрузки DOM
document.addEventListener('DOMContentLoaded', () => {
    console.log('SidebarManager: DOM загружен, начинаем инициализацию');
    
    // Небольшая задержка для избежания конфликтов с другими скриптами
    setTimeout(() => {
        window.sidebarManager.init();
    }, 100);
});
