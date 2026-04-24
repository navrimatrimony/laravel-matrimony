{{-- Synchronous toastr shim: works before Vite modules load. Use window.toastr.error(message). --}}
<script>
(function () {
    function stack() {
        var s = document.getElementById('app-toast-stack');
        if (!s) {
            s = document.createElement('div');
            s.id = 'app-toast-stack';
            s.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:99999;display:flex;flex-direction:column;gap:0.5rem;max-width:min(22rem,calc(100vw - 2rem));pointer-events:none';
            document.body.appendChild(s);
        }
        return s;
    }
    function pushToast(message, tone) {
        var text = String(message || '').trim();
        if (!text) {
            return;
        }
        var el = document.createElement('div');
        el.setAttribute('role', 'status');
        el.style.cssText = 'pointer-events:auto;padding:0.65rem 1rem;border-radius:0.5rem;font-size:0.875rem;line-height:1.4;box-shadow:0 10px 25px rgba(0,0,0,.15);' + (tone === 'error' ? 'background:#b91c1c;color:#fff;' : 'background:#065f46;color:#fff;');
        el.textContent = text;
        stack().appendChild(el);
        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.25s ease';
            setTimeout(function () {
                if (el.parentNode) {
                    el.remove();
                }
            }, 280);
        }, 6500);
    }
    window.toastr = window.toastr || {};
    if (typeof window.toastr.error !== 'function') {
        window.toastr.error = function (m) {
            pushToast(m, 'error');
        };
    }
    if (typeof window.toastr.success !== 'function') {
        window.toastr.success = function (m) {
            pushToast(m, 'success');
        };
    }
})();
</script>
