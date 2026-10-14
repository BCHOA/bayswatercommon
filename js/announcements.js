// Bayswater Common HOA — home page announcements
(function () {
  // Active announcements come from the HOA admin site. Their "html" is built
  // on the server from plain text, with everything escaped except a few safe tags.
  var ANNOUNCEMENTS_URL = 'https://admin.bayswatercommon.org/api/announcements.php';

  function formatDate(ymd) {
    var d = new Date(ymd + 'T00:00:00Z');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
  }

  function showMessage(box, text) {
    box.innerHTML = '';
    var p = document.createElement('div');
    p.className = 'no-announcements';
    p.textContent = text;
    box.appendChild(p);
  }

  function showAnnouncements(list) {
    var box = document.getElementById('announcementsList');
    if (!list.length) { showMessage(box, 'No active announcements.'); return; }
    box.innerHTML = '';
    list.forEach(function (a) {
      var item = document.createElement('div');
      item.className = 'announcement';
      var date = document.createElement('div');
      date.className = 'announcement-date';
      date.textContent = 'Since ' + formatDate(a.start_date);
      item.appendChild(date);
      if (a.title) {
        var t = document.createElement('div');
        t.className = 'announcement-title';
        t.textContent = a.title;
        item.appendChild(t);
      }
      var body = document.createElement('div');
      body.className = 'announcement-text';
      body.innerHTML = a.html;
      item.appendChild(body);
      box.appendChild(item);
    });
  }

  fetch(ANNOUNCEMENTS_URL, { credentials: 'omit' })
    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(function (data) { showAnnouncements(data.announcements || []); })
    .catch(function () {
      showMessage(document.getElementById('announcementsList'), 'Announcements could not be loaded right now. Please try again later.');
    });
})();
