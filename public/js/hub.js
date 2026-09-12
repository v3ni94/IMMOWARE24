/*
 * Immoware Hub, minimales Vanilla-JS ohne Abhängigkeiten. Alle Funktionen sind Ergänzungen,
 * die Oberfläche bleibt ohne JavaScript vollständig bedienbar (Formulare, details/summary).
 */
(function () {
    'use strict';

    // Navigation auf schmalen Bildschirmen ein- und ausblenden.
    document.querySelectorAll('[data-hub-toggle]').forEach(function (button) {
        var target = document.getElementById(button.getAttribute('data-hub-toggle'));

        if (!target) {
            return;
        }

        button.addEventListener('click', function () {
            var open = target.classList.toggle('is-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    // Bestätigungsformulare: Absenden erst, wenn das Bestätigungswort exakt eingegeben wurde,
    // und Doppelklicks auf Absenden verhindern.
    document.querySelectorAll('form[data-hub-confirm]').forEach(function (form) {
        var word = form.getAttribute('data-hub-confirm-word') || '';
        var input = form.querySelector('[data-hub-confirm-input]');
        var submit = form.querySelector('[data-hub-confirm-submit]');

        if (!input || !submit) {
            return;
        }

        var sync = function () {
            submit.disabled = input.value.trim() !== word;
        };

        input.addEventListener('input', sync);
        sync();

        form.addEventListener('submit', function (event) {
            if (input.value.trim() !== word) {
                event.preventDefault();
                input.focus();

                return;
            }

            submit.disabled = true;
            submit.setAttribute('aria-disabled', 'true');
        });
    });

    // Flash-Meldungen nach 12 Sekunden ausblenden, Fehler bleiben stehen.
    document.querySelectorAll('.hub-alert-success, .hub-alert-info').forEach(function (alert) {
        window.setTimeout(function () {
            alert.style.transition = 'opacity 0.4s';
            alert.style.opacity = '0';
            window.setTimeout(function () {
                alert.remove();
            }, 450);
        }, 12000);
    });
})();
