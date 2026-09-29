(function () {
    document.querySelectorAll('[data-toggle-for]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.toggleFor);
            var hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            btn.querySelector('i').className = hidden ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });

    var pw = document.getElementById('new_password');
    var confirmPw = document.getElementById('confirm_password');
    var matchText = document.getElementById('match-text');
    if (!pw || !confirmPw || !matchText) { return; }

    function updateMatch() {
        if (confirmPw.value === '') { matchText.textContent = ''; return; }
        var ok = confirmPw.value === pw.value;
        matchText.textContent = ok ? 'Passwords match.' : 'Passwords do not match yet.';
        matchText.style.color = ok ? 'var(--status-success-fg)' : 'var(--status-danger-fg)';
    }
    pw.addEventListener('input', updateMatch);
    confirmPw.addEventListener('input', updateMatch);
})();
