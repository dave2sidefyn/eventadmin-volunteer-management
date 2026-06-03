(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form.eventadmin-form');
        if (!form || typeof grecaptcha === 'undefined') return;

        form.addEventListener('submit', function (e) {
            var tokenField = document.getElementById('g-recaptcha-response-v3');
            if (!tokenField || tokenField.value) return;

            e.preventDefault();
            grecaptcha.ready(function () {
                grecaptcha.execute(eventadminCaptchaV3.siteKey, { action: 'register' }).then(function (token) {
                    tokenField.value = token;
                    form.submit();
                });
            });
        });
    });
}());
