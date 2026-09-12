/*
 * Mail und Vorgänge: Tastaturbedienung ohne Abhängigkeiten. Die Oberfläche bleibt ohne JavaScript vollständig
 * bedienbar. Kürzel: j/k nächste und vorige Zeile, Enter oder e öffnen, a Zuweisen, r Antworten, ? Hilfe.
 * In Eingabefeldern sind die Kürzel inaktiv.
 */
(function () {
    'use strict';

    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-mail-row]'));
    var help = document.getElementById('mail-shortcut-help');
    var index = -1;

    function isEditing(target) {
        if (!target) {
            return false;
        }

        var tag = (target.tagName || '').toLowerCase();

        return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
    }

    function focusRow(next) {
        if (rows.length === 0) {
            return;
        }

        index = Math.max(0, Math.min(rows.length - 1, next));
        rows.forEach(function (row) { row.classList.remove('is-current'); });
        rows[index].classList.add('is-current');
        rows[index].focus({ preventScroll: false });
        rows[index].scrollIntoView({ block: 'nearest' });
    }

    function currentUrl() {
        if (index < 0 || !rows[index]) {
            return null;
        }

        return rows[index].getAttribute('data-mail-url');
    }

    function focusField(selector) {
        var field = document.querySelector(selector);

        if (field) {
            field.focus();
            field.scrollIntoView({ block: 'center' });

            return true;
        }

        return false;
    }

    function toggleHelp() {
        if (!help) {
            return;
        }

        if (help.open) {
            help.close();
        } else if (typeof help.showModal === 'function') {
            help.showModal();
        } else {
            help.setAttribute('open', 'open');
        }
    }

    rows.forEach(function (row, i) {
        row.addEventListener('focus', function () { index = i; });
        row.addEventListener('dblclick', function () {
            var url = row.getAttribute('data-mail-url');

            if (url) {
                window.location.href = url;
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (event.key === 'Escape' && help && help.open) {
            help.close();

            return;
        }

        if (isEditing(event.target)) {
            return;
        }

        switch (event.key) {
            case 'j':
                event.preventDefault();
                focusRow(index + 1);
                break;
            case 'k':
                event.preventDefault();
                focusRow(index - 1);
                break;
            case 'Enter':
            case 'e':
                if (currentUrl()) {
                    event.preventDefault();
                    window.location.href = currentUrl();
                }
                break;
            case 'a':
                event.preventDefault();
                if (!focusField('[data-mail-assign-field]') && currentUrl()) {
                    window.location.href = currentUrl() + '#assign';
                }
                break;
            case 'r':
                event.preventDefault();
                focusField('#d-body');
                break;
            case '?':
                event.preventDefault();
                toggleHelp();
                break;
            default:
                break;
        }
    });

    // Hauptaktion je Bearbeitungsstand: Hinweis im Kontext blinkt nicht, sondern bekommt einen Marker.
    var primary = document.querySelector('.mail-primary');

    if (primary) {
        primary.setAttribute('data-mail-primary-action', 'true');
    }
})();
