(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form.eventadmin-form');
        if (!form || typeof grecaptcha === 'undefined') return;

        form.addEventListener('submit', function (e) {
            var tokenField = document.getElementById('g-recaptcha-response-v3');
            if (!tokenField || tokenField.value) return;

            e.preventDefault();

            var done = false;
            var submitOnce = function () {
                if (!done) {
                    done = true;
                    form.submit();
                }
            };

            // Safety net: submit after 5 s even if reCAPTCHA never responds
            var timeout = setTimeout(submitOnce, 5000);

            try {
                grecaptcha.ready(function () {
                    grecaptcha.execute(eventadminCaptchaV3.siteKey, { action: 'register' })
                        .then(function (token) {
                            clearTimeout(timeout);
                            tokenField.value = token;
                            submitOnce();
                        })
                        .catch(function () {
                            clearTimeout(timeout);
                            submitOnce();
                        });
                });
            } catch (err) {
                clearTimeout(timeout);
                submitOnce();
            }
        });
    });
}());
