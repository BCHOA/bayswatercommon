// Bayswater Common HOA — meeting minutes on the Documents page.
// The list comes from the HOA admin site, where board members upload files and add links.
(function () {
  var URL_LIST = 'https://admin.bayswatercommon.org/api/minutes.php';
  var box = document.getElementById('minutesList');
  if (!box) return;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }

  function message(text) {
    box.innerHTML = '';
    box.appendChild(el('div', 'no-announcements', text));
  }

  function formatDate(ymd) {
    var d = new Date(ymd + 'T00:00:00Z');
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
  }

  function safeUrl(u) {
    return /^https:\/\//i.test(u) ? u : null;
  }

  function render(meetings) {
    if (!meetings.length) { message('No meeting minutes have been posted yet.'); return; }
    box.innerHTML = '';
    meetings.forEach(function (m) {
      var card = el('article', 'meeting');
      card.appendChild(el('div', 'meeting-date', formatDate(m.date)));
      card.appendChild(el('div', 'meeting-title', m.title));
      if (m.notes) card.appendChild(el('p', 'meeting-notes', m.notes));
      var list = el('ul', 'meeting-items');
      (m.items || []).forEach(function (it) {
        var url = safeUrl(it.url);
        if (!url) return;
        var li = el('li');
        li.appendChild(el('span', 'kind', it.kind));
        var a = el('a', null, it.label);
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        li.appendChild(a);
        if (it.kind === 'audio') {
          var au = document.createElement('audio');
          au.controls = true;
          au.preload = 'none';
          au.src = url;
          li.appendChild(au);
        }
        list.appendChild(li);
      });
      card.appendChild(list);
      box.appendChild(card);
    });
  }

  fetch(URL_LIST, { credentials: 'omit' })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(function (data) { render(data.meetings || []); })
    .catch(function () { message('Meeting minutes could not be loaded right now. Please try again later.'); });
})();
