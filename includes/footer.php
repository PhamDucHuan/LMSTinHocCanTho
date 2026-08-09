        </div> <!-- end page-content -->
        <footer class="lms-footer">
            <span><i class='bx bx-code-alt'></i> DESIGN BY: <strong>PHAM DUC HUAN</strong></span>
        </footer>
        <style>
            .lms-footer{margin-top:auto;padding:18px 40px;border-top:1px solid var(--border-color);color:var(--text-muted);font-size:13px;text-align:center;background:rgba(var(--primary-rgb),.025)}
            .lms-footer span{display:inline-flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
            .lms-footer i{color:var(--primary);font-size:18px}
            .lms-footer strong{color:var(--text-main);font-weight:600}
            @media(max-width:700px){.lms-footer{padding:16px 14px}}
        </style>
    </main> <!-- end main-content -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const root = document.documentElement;
            const themeToggle = document.getElementById('theme-toggle');
            const themePanel = document.getElementById('theme-panel');
            const themeClose = document.getElementById('theme-close');
            const themeCustomColor = document.getElementById('theme-custom-color');
            const defaultPrimary = document.body.dataset.defaultPrimary || '#6366f1';
            const systemTheme = window.matchMedia('(prefers-color-scheme: light)');

            window.lmsRefreshCharts = function() {
                if (typeof Chart === 'undefined' || !Chart.instances) return;
                const styles = getComputedStyle(document.documentElement);
                const textColor = styles.getPropertyValue('--text-main').trim() || '#f8fafc';
                const mutedColor = styles.getPropertyValue('--text-muted').trim() || '#94a3b8';
                const borderColor = styles.getPropertyValue('--border-color').trim() || 'rgba(255,255,255,.1)';

                Object.values(Chart.instances).forEach(chart => {
                    const legendLabels = chart.options?.plugins?.legend?.labels;
                    if (legendLabels) legendLabels.color = textColor;
                    Object.values(chart.options?.scales || {}).forEach(scale => {
                        if (scale.ticks) scale.ticks.color = mutedColor;
                        if (scale.grid && scale.grid.display !== false) scale.grid.color = borderColor;
                        if (scale.title) scale.title.color = textColor;
                    });
                    chart.update('none');
                });
            };

            function readTheme() {
                try { return JSON.parse(localStorage.getItem('lms_theme') || '{}'); }
                catch (_) { return {}; }
            }
            function hexToRgb(hex) {
                return `${parseInt(hex.slice(1,3),16)}, ${parseInt(hex.slice(3,5),16)}, ${parseInt(hex.slice(5,7),16)}`;
            }
            function applyTheme(mode, primary, save = true) {
                const safeMode = ['dark', 'light', 'system', 'ocean', 'forest', 'violet', 'sunset', 'universe'].includes(mode) ? mode : 'dark';
                const safePrimary = /^#[0-9a-f]{6}$/i.test(primary || '') ? primary : defaultPrimary;
                const resolved = safeMode === 'system' ? (systemTheme.matches ? 'light' : 'dark') : safeMode;
                root.dataset.theme = resolved;
                root.dataset.themeMode = safeMode;
                root.style.setProperty('--primary', safePrimary);
                root.style.setProperty('--primary-rgb', hexToRgb(safePrimary));
                requestAnimationFrame(() => window.lmsRefreshCharts?.());
                if (themeCustomColor) themeCustomColor.value = safePrimary;
                document.querySelectorAll('[data-theme-mode]').forEach(button => button.classList.toggle('active', button.dataset.themeMode === safeMode));
                document.querySelectorAll('[data-theme-color]').forEach(button => button.classList.toggle('active', button.dataset.themeColor.toLowerCase() === safePrimary.toLowerCase()));
                if (save) localStorage.setItem('lms_theme', JSON.stringify({ mode: safeMode, primary: safePrimary }));
            }
            const savedTheme = readTheme();
            applyTheme(savedTheme.mode || 'dark', savedTheme.primary || defaultPrimary, false);
            systemTheme.addEventListener?.('change', () => {
                const current = readTheme();
                if ((current.mode || 'dark') === 'system') applyTheme('system', current.primary || defaultPrimary, false);
            });
            themeToggle?.addEventListener('click', event => {
                event.stopPropagation();
                themePanel.hidden = !themePanel.hidden;
                themeToggle.setAttribute('aria-expanded', String(!themePanel.hidden));
            });
            themeClose?.addEventListener('click', () => {
                themePanel.hidden = true;
                themeToggle?.setAttribute('aria-expanded', 'false');
            });
            themePanel?.addEventListener('click', event => event.stopPropagation());
            document.addEventListener('click', () => {
                if (themePanel && !themePanel.hidden) {
                    themePanel.hidden = true;
                    themeToggle?.setAttribute('aria-expanded', 'false');
                }
            });
            document.querySelectorAll('[data-theme-mode]').forEach(button => button.addEventListener('click', () => {
                const current = readTheme();
                applyTheme(button.dataset.themeMode, current.primary || defaultPrimary);
            }));
            document.querySelectorAll('[data-theme-color]').forEach(button => button.addEventListener('click', () => {
                const current = readTheme();
                applyTheme(current.mode || 'dark', button.dataset.themeColor);
            }));
            themeCustomColor?.addEventListener('input', () => {
                const current = readTheme();
                applyTheme(current.mode || 'dark', themeCustomColor.value);
            });
            document.getElementById('theme-reset')?.addEventListener('click', () => applyTheme('dark', defaultPrimary));

            document.addEventListener('pointerdown', event => {
                const button = event.target.closest('.btn');
                if (!button || button.matches(':disabled')) return;
                const rect = button.getBoundingClientRect();
                const ripple = document.createElement('span');
                const size = Math.max(rect.width, rect.height) * 2;
                ripple.className = 'lms-ripple';
                ripple.style.width = `${size}px`;
                ripple.style.height = `${size}px`;
                ripple.style.left = `${event.clientX - rect.left}px`;
                ripple.style.top = `${event.clientY - rect.top}px`;
                button.appendChild(ripple);
                ripple.addEventListener('animationend', () => ripple.remove(), { once: true });
            });

            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!reducedMotion) {
                const animateNumber = element => {
                    const raw = element.textContent.trim().replace(',', '.');
                    const target = Number(raw);
                    if (!Number.isFinite(target)) return;
                    const decimals = raw.includes('.') ? raw.split('.')[1].length : 0;
                    const startedAt = performance.now();
                    const duration = 700;
                    const step = now => {
                        const progress = Math.min(1, (now - startedAt) / duration);
                        const eased = 1 - Math.pow(1 - progress, 3);
                        element.textContent = (target * eased).toFixed(decimals);
                        if (progress < 1) requestAnimationFrame(step);
                    };
                    requestAnimationFrame(step);
                };
                const numberObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (!entry.isIntersecting) return;
                        animateNumber(entry.target);
                        observer.unobserve(entry.target);
                    });
                }, { threshold: .35 });
                document.querySelectorAll('.stat-number').forEach(element => numberObserver.observe(element));
            }

            window.lmsCelebrate = () => {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                const colors = ['#f43f5e','#f59e0b','#10b981','#38bdf8','#8b5cf6'];
                for (let index = 0; index < 18; index += 1) {
                    const piece = document.createElement('i');
                    const angle = (Math.PI * 2 * index) / 18;
                    const distance = 95 + Math.random() * 105;
                    piece.className = 'lms-confetti-piece';
                    piece.style.background = colors[index % colors.length];
                    piece.style.setProperty('--confetti-x', `${Math.cos(angle) * distance}px`);
                    piece.style.setProperty('--confetti-y', `${Math.sin(angle) * distance + 80}px`);
                    piece.style.setProperty('--confetti-r', `${360 + Math.random() * 540}deg`);
                    piece.style.animationDelay = `${Math.random() * 90}ms`;
                    document.body.appendChild(piece);
                    piece.addEventListener('animationend', () => piece.remove(), { once: true });
                }
            };

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            if (csrf) document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function(form) {
                if (!form.querySelector('input[name="csrf_token"]')) {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'csrf_token'; input.value = csrf; form.appendChild(input);
                }
            });
            const sidebarToggleOpen = document.getElementById('sidebar-toggle-open');
            const sidebarToggleClose = document.getElementById('sidebar-toggle-close');
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.getElementById('main-content');

            const sidebarGroups = Array.from(document.querySelectorAll('[data-sidebar-group]'));
            const setSidebarGroupCollapsed = (group, collapsed) => {
                group.classList.toggle('collapsed', collapsed);
                group.querySelector('.sidebar-group-toggle')
                    ?.setAttribute('aria-expanded', String(!collapsed));
            };

            // Mỗi lần chuyển trang, chỉ mở nhóm chứa mục đang hoạt động.
            // Không dùng trạng thái cũ để tránh mở nhầm một nhóm khác.
            sidebarGroups.forEach(group => {
                setSidebarGroupCollapsed(group, !group.classList.contains('has-active'));
                const toggle = group.querySelector('.sidebar-group-toggle');
                toggle?.addEventListener('click', () => {
                    const willExpand = group.classList.contains('collapsed');
                    setSidebarGroupCollapsed(group, !willExpand);
                });
            });
            
            if (sidebar && mainContent) {
                const mobileSidebar = window.matchMedia('(max-width: 900px)');
                const backdrop = document.createElement('button');
                backdrop.type = 'button';
                backdrop.className = 'sidebar-backdrop';
                backdrop.setAttribute('aria-label', 'Đóng trình đơn');
                document.body.appendChild(backdrop);

                function setSidebar(collapsed) {
                    sidebar.classList.toggle('collapsed', collapsed);
                    mainContent.classList.toggle('expanded', collapsed);
                    document.body.classList.toggle('sidebar-mobile-open', mobileSidebar.matches && !collapsed);
                    sidebarToggleOpen?.setAttribute('aria-expanded', String(!collapsed));
                }

                function syncSidebarLayout() {
                    const desktopCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
                    setSidebar(mobileSidebar.matches ? true : desktopCollapsed);
                }

                syncSidebarLayout();
                 
                function toggleSidebar() {
                    const willCollapse = !sidebar.classList.contains('collapsed');
                    setSidebar(willCollapse);
                    if (!mobileSidebar.matches) {
                        localStorage.setItem('sidebar_collapsed', String(willCollapse));
                    }
                }
                 
                if (sidebarToggleOpen) sidebarToggleOpen.addEventListener('click', toggleSidebar);
                if (sidebarToggleClose) sidebarToggleClose.addEventListener('click', toggleSidebar);
                backdrop.addEventListener('click', () => setSidebar(true));
                sidebar.querySelectorAll('.menu-item').forEach(link => {
                    link.addEventListener('click', () => {
                        if (mobileSidebar.matches) setSidebar(true);
                    });
                });
                mobileSidebar.addEventListener?.('change', syncSidebarLayout);
            }

            document.querySelectorAll('.page-content table').forEach(table => {
                if (table.closest('.table-responsive')) return;
                const wrapper = document.createElement('div');
                wrapper.className = 'table-responsive';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            });
        });
    </script>
</body>
</html>
