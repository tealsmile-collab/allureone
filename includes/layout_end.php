        </div>
    </main>
    <?php
    require_once __DIR__ . '/app_client.php';
    $dinggSyncCsrf = function_exists('csrf_token') ? csrf_token() : '';
    ?>
    <script>
    (function () {
        var csrf = <?= json_encode($dinggSyncCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var lsKey = (document.body && document.body.getAttribute('data-dingg-ls-key')) || <?= json_encode(ALLUREONE_LS_DINGG_BEARER, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        if (csrf) {
            try {
                var t = localStorage.getItem(lsKey);
                fetch('dingg_token_sync.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-AllureOne-Dingg-Token': t || ''
                    },
                    body: JSON.stringify({ _csrf: csrf }),
                    credentials: 'same-origin'
                }).catch(function () {});
            } catch (e) {}
        }
    })();
    </script>
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
