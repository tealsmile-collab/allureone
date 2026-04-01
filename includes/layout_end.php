        </div>
    </main>
    <script>
    (function () {
        var body = document.body;
        var btn = document.getElementById('menuToggle');
        if (!body || !btn) return;
        var key = 'allureone_menu_collapsed';
        var isCollapsed = localStorage.getItem(key) === '1';
        function applyState() {
            body.classList.toggle('menu-collapsed', isCollapsed);
            btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        }
        btn.addEventListener('click', function () {
            isCollapsed = !isCollapsed;
            localStorage.setItem(key, isCollapsed ? '1' : '0');
            applyState();
        });
        applyState();
    })();
    </script>
</body>
</html>
