/*
 * Planner Mini App: только статика + вызовы REST API.
 * Любая бизнес-логика (парсинг дат, сортировка, права) — на бэкенде (Шаг 6).
 */
(function () {
    'use strict';

    var tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    var initDataRaw = tg ? tg.initData : '';

    var $ = function (id) { return document.getElementById(id); };
    var state = { date: null, data: null };

    function iso(d) { return d.toISOString().slice(0, 10); }

    function setStatus(msg) { $('status').textContent = msg || ''; }

    function api(method, path, body) {
        return fetch('/api/webapp' + path, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Telegram-Init-Data': initDataRaw
            },
            body: body ? JSON.stringify(body) : null
        }).then(function (r) {
            if (!r.ok) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    throw new Error(j.message || ('HTTP ' + r.status));
                });
            }
            return r.json();
        });
    }

    function renderList(el, emptyEl, items, isScheduled) {
        el.innerHTML = '';
        emptyEl.style.display = items.length ? 'none' : 'block';

        items.forEach(function (t) {
            var li = document.createElement('li');
            li.className = t.status === 'completed' ? 'done' : '';

            var btn = document.createElement('button');
            btn.textContent = t.status === 'completed' ? '↩' : '✅';
            btn.title = t.status === 'completed' ? 'Вернуть в работу' : 'Выполнить';
            btn.onclick = function () { toggle(t); };

            var mid = document.createElement(isScheduled ? 'span' : 'div');
            if (isScheduled) {
                mid.className = 'time';
                mid.textContent = t.scheduled_time || '--:--';
            }

            var title = document.createElement('span');
            title.className = 'title';
            title.textContent = t.title;

            var meta = document.createElement('span');
            meta.className = 'meta';
            meta.textContent = isScheduled
                ? (t.tags && t.tags.length ? '#' + t.tags.join(' #') : '')
                : [t.priority_label !== 'не указан' ? t.priority_label : null,
                   t.estimated_minutes ? t.estimated_minutes + ' мин' : null]
                    .filter(Boolean).join(', ');

            var del = document.createElement('button');
            del.textContent = '🗑';
            del.title = 'Удалить';
            del.onclick = function () { remove(t); };

            li.appendChild(btn);
            if (isScheduled) li.appendChild(mid);
            li.appendChild(title);
            li.appendChild(meta);
            li.appendChild(del);
            el.appendChild(li);
        });
    }

    function render() {
        var d = state.data.data;
        $('goal').textContent = d.goal ? ('🎯 ' + d.goal) : '🎯 Цель не задана';
        renderList($('scheduled'), $('scheduled-empty'), d.scheduled, true);
        renderList($('flexible'), $('flexible-empty'), d.flexible, false);
        var s = d.stats;
        $('stats').textContent = 'Прогресс: ' + (s.schedule_done + s.tasks_done) + '/'
            + (s.schedule_total + s.tasks_total)
            + (s.total_estimated_minutes ? ' · задач на ' + s.total_estimated_minutes + ' мин' : '');
        if (tg) tg.HapticFeedback && tg.HapticFeedback.impactOccurred && tg.ready();
    }

    function load() {
        setStatus('Загрузка…');
        api('GET', '/day?date=' + state.date).then(function (json) {
            state.data = json; render(); setStatus('');
        }).catch(function (e) { setStatus('⚠ ' + e.message); });
    }

    function shift(days) {
        var d = new Date(state.date + 'T00:00:00');
        d.setDate(d.getDate() + days);
        state.date = iso(d);
        load();
    }

    function toggle(t) {
        var next = t.status === 'completed' ? 'pending' : 'completed';
        api('PATCH', '/tasks/' + encodeURIComponent(t.id), { status: next }).then(load).catch(function (e) { setStatus('⚠ ' + e.message); });
    }

    function remove(t) {
        api('DELETE', '/tasks/' + encodeURIComponent(t.id)).then(load).catch(function (e) { setStatus('⚠ ' + e.message); });
    }

    function bind() {
        $('prev').onclick = function () { shift(-1); };
        $('next').onclick = function () { shift(1); };
        $('today').onclick = function () { state.date = iso(new Date()); load(); };
        $('goal-set').onclick = function () { $('goal-dialog').showModal(); };
        $('goal-cancel').onclick = function () { $('goal-dialog').close(); };

        $('add-form').onsubmit = function (e) {
            e.preventDefault();
            var type = $('f-type').value;
            var body = {
                type: type,
                task_date: $('f-date').value || state.date,
                title: $('f-title').value.trim()
            };
            if (type === 'scheduled' && $('f-time').value) body.scheduled_time = $('f-time').value;
            if (type === 'flexible') {
                if ($('f-minutes').value !== '') body.estimated_minutes = parseInt($('f-minutes').value, 10);
                body.priority = parseInt($('f-priority').value, 10);
            }
            setStatus('Сохранение…');
            api('POST', '/tasks', body).then(function () {
                e.target.reset();
                load();
            }).catch(function (err) { setStatus('⚠ ' + err.message); });
        };

        $('goal-form').onsubmit = function (e) {
            e.preventDefault();
            api('POST', '/goal', { date: state.date, goal: $('goal-text').value.trim() })
                .then(function () { $('goal-dialog').close(); load(); })
                .catch(function (err) { setStatus('⚠ ' + err.message); });
        };
    }

    if (tg) { tg.ready(); tg.expand(); }

    if (!initDataRaw) {
        setStatus('Откройте через кнопку Mini App в боте Telegram.');
    }

    bind();
    state.date = iso(new Date());
    $('f-date').value = state.date;

    if (initDataRaw) { load(); }
})();
