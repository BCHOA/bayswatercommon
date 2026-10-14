// Bayswater Common HOA — shared page behaviour
(function () {
  var y = document.getElementById('y');
  if (y) y.textContent = new Date().getFullYear();

  // On phones, the Google Calendar month grid is too cramped: show the agenda (list) view instead.
  var cal = document.getElementById('hoaCalendar');
  if (cal && window.matchMedia && window.matchMedia('(max-width: 600px)').matches) {
    cal.src = cal.src.replace('mode=MONTH', 'mode=AGENDA');
  }
})();
