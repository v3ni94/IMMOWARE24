<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 0 1rem 2rem; background: #f7f7f5; color: #1c1c1c; }
        header { padding: 1.25rem 0 0.5rem; border-bottom: 1px solid #d9d9d4; }
        h1 { font-size: 1.4rem; margin: 0 0 0.25rem; }
        .hint { font-size: 0.9rem; color: #555; }
        .key { margin: 1rem 0; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .key input { flex: 1 1 24rem; min-width: 12rem; padding: 0.4rem 0.6rem; border: 1px solid #b9b9b2; border-radius: 4px; font-family: monospace; }
        .key button { padding: 0.45rem 0.9rem; border: 1px solid #1f3b57; background: #1f3b57; color: #fff; border-radius: 4px; cursor: pointer; }
        .intro { white-space: pre-wrap; background: #fff; border: 1px solid #e2e2dc; padding: 0.75rem 1rem; border-radius: 4px; font-size: 0.92rem; }
        .tag { margin-top: 1.5rem; }
        .tag h2 { font-size: 1.1rem; margin: 0 0 0.5rem; border-bottom: 1px solid #d9d9d4; padding-bottom: 0.25rem; }
        .op { background: #fff; border: 1px solid #e2e2dc; border-radius: 4px; margin-bottom: 0.5rem; padding: 0.5rem 0.75rem; }
        .op summary { cursor: pointer; display: flex; gap: 0.75rem; align-items: baseline; flex-wrap: wrap; }
        .method { font-family: monospace; font-weight: 700; min-width: 4.5rem; }
        .method.get { color: #1f6f43; } .method.post { color: #8a4b08; } .method.patch { color: #5b3a8a; } .method.delete { color: #9b1c1c; }
        .path { font-family: monospace; }
        .meta { font-size: 0.85rem; color: #555; }
        .meta span { margin-right: 0.75rem; }
        table { border-collapse: collapse; width: 100%; font-size: 0.88rem; margin-top: 0.5rem; }
        td, th { border: 1px solid #e2e2dc; padding: 0.25rem 0.5rem; text-align: left; vertical-align: top; }
        .error { color: #9b1c1c; }
        code { font-family: monospace; }
    </style>
</head>
<body>
<header>
    <h1>{{ $title }}</h1>
    <div class="hint">Die Spezifikation wird von <code>{{ $specUrl }}</code> geladen und benötigt einen gültigen API-Key. Der Key bleibt nur in diesem Browserfenster.</div>
</header>
<div class="key">
    <input id="apikey" type="password" placeholder="hub_live_..." autocomplete="off">
    <button id="load" type="button">Spezifikation laden</button>
</div>
<div id="status" class="hint"></div>
<div id="intro" class="intro" hidden></div>
<div id="content"></div>
<script>
(function () {
    var specUrl = @json($specUrl);
    var input = document.getElementById('apikey');
    var status = document.getElementById('status');
    var intro = document.getElementById('intro');
    var content = document.getElementById('content');

    try { input.value = window.sessionStorage.getItem('hub_api_key') || ''; } catch (e) {}

    function esc(value) {
        return String(value === undefined || value === null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function render(spec) {
        intro.hidden = false;
        intro.textContent = (spec.info && spec.info.description) || '';
        var byTag = {};
        Object.keys(spec.paths || {}).forEach(function (path) {
            Object.keys(spec.paths[path]).forEach(function (method) {
                var op = spec.paths[path][method];
                var tag = (op.tags && op.tags[0]) || 'sonstige';
                (byTag[tag] = byTag[tag] || []).push({path: path, method: method, op: op});
            });
        });
        var html = '';
        Object.keys(byTag).sort().forEach(function (tag) {
            html += '<section class="tag"><h2>' + esc(tag) + '</h2>';
            byTag[tag].forEach(function (item) {
                var op = item.op;
                var params = (op.parameters || []).map(function (p) {
                    if (p.$ref) { var name = p.$ref.split('/').pop(); return '<tr><td><code>' + esc(name) + '</code></td><td>query</td><td>siehe components.parameters</td></tr>'; }
                    return '<tr><td><code>' + esc(p.name) + '</code></td><td>' + esc(p.in) + (p.required ? ' (Pflicht)' : '') + '</td><td>' + esc(p.description || '') + '</td></tr>';
                }).join('');
                var responses = Object.keys(op.responses || {}).map(function (code) {
                    var r = op.responses[code];
                    var desc = r.description || (r.$ref ? r.$ref.split('/').pop() : '');
                    return '<tr><td><code>' + esc(code) + '</code></td><td colspan="2">' + esc(desc) + '</td></tr>';
                }).join('');
                html += '<details class="op"><summary><span class="method ' + esc(item.method) + '">' + esc(item.method.toUpperCase()) + '</span><span class="path">' + esc(item.path) + '</span><span>' + esc(op.summary || '') + '</span></summary>'
                    + '<div class="meta"><span>Scopes: ' + esc((op['x-scope'] || []).join(', ') || 'keiner') + '</span><span>Wirkung: ' + esc(op['x-effect']) + '</span><span>Beleg: ' + esc(op['x-evidence-status'] || 'n/a') + '</span><span>Phase: ' + esc(op['x-phase']) + '</span></div>'
                    + (params ? '<table><tr><th>Parameter</th><th>Ort</th><th>Beschreibung</th></tr>' + params + '</table>' : '')
                    + (responses ? '<table><tr><th>Status</th><th colspan="2">Antwort</th></tr>' + responses + '</table>' : '')
                    + '</details>';
            });
            html += '</section>';
        });
        var hooks = Object.keys(spec.webhooks || {});
        if (hooks.length) {
            html += '<section class="tag"><h2>Webhook-Ereignisse</h2><table><tr><th>Ereignis</th><th>Beschreibung</th></tr>';
            hooks.forEach(function (name) { html += '<tr><td><code>' + esc(name) + '</code></td><td>' + esc(spec.webhooks[name].post.summary) + '</td></tr>'; });
            html += '</table></section>';
        }
        content.innerHTML = html;
    }

    function load() {
        var key = input.value.trim();
        try { window.sessionStorage.setItem('hub_api_key', key); } catch (e) {}
        status.textContent = 'Lade ...';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', specUrl, true);
        if (key) { xhr.setRequestHeader('Authorization', 'Bearer ' + key); }
        xhr.onload = function () {
            if (xhr.status !== 200) { status.innerHTML = '<span class="error">Fehler ' + esc(xhr.status) + ': Spezifikation konnte nicht geladen werden.</span>'; return; }
            try { render(JSON.parse(xhr.responseText)); status.textContent = ''; }
            catch (e) { status.innerHTML = '<span class="error">Antwort konnte nicht gelesen werden.</span>'; }
        };
        xhr.onerror = function () { status.innerHTML = '<span class="error">Netzwerkfehler.</span>'; };
        xhr.send();
    }

    document.getElementById('load').addEventListener('click', load);
    if (input.value) { load(); }
})();
</script>
</body>
</html>
