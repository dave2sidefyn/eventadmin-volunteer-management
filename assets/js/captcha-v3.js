(function () {
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof grecaptcha === 'undefined') return;

        // The volunteer page holds two forms ("I'm new" / "I already have an account"), so
        // every form gets its own handler and fills the token field inside itself.
        document.querySelectorAll('form.eventadmin-form').forEach(function (form) {
            var tokenField = form.querySelector('.eventadmin-recaptcha-v3-token');
            if (!tokenField) return;

            form.addEventListener('submit', function (e) {
                if (tokenField.value) return;

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
    });
}());
