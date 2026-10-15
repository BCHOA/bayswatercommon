/**
 * Bayswater Common HOA website -> Google Calendar bridge.
 *
 * Runs inside the BayswaterCommon@gmail.com Google account (script.google.com) and adds events
 * to the HOA calendar when the website (admin.bayswatercommon.org) asks it to.
 * No Google Cloud project, billing, or keys needed.
 *
 * SETUP: fill in the two values below, then Deploy > New deployment > Web app,
 *        Execute as: Me, Who has access: Anyone. Copy the Web app URL into the website's Settings page.
 */

// The code shown on the website's Settings page ("Calendar link code"). Keep it private.
const SECRET = 'PASTE-THE-CODE-FROM-THE-WEBSITE-SETTINGS-PAGE';

// The HOA calendar's ID (Google Calendar > the calendar's Settings > Integrate calendar > Calendar ID).
const CALENDAR_ID = 'bayswatercommon@gmail.com';

const TIME_ZONE = 'America/New_York';

function doPost(e) {
  let req;
  try {
    req = JSON.parse(e.postData.contents);
  } catch (err) {
    return reply({ ok: false, error: 'The request was not understood.' });
  }
  if (!req || typeof req.secret !== 'string' || req.secret !== SECRET) {
    return reply({ ok: false, error: 'The calendar link code does not match. Check it on the website Settings page and in this script.' });
  }
  // Send an email from this Gmail account (replies to people who post by email, board approval requests).
  if (req.action === 'mail') {
    const m = req.mail || {};
    const to = String(m.to || '');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) {
      return reply({ ok: false, error: 'Bad recipient address.' });
    }
    const options = { name: 'Bayswater Common HOA' };
    if (m.reply_to && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(m.reply_to))) {
      options.replyTo = String(m.reply_to);
    }
    try {
      GmailApp.sendEmail(to, String(m.subject || '').slice(0, 250), String(m.body || '').slice(0, 20000), options);
    } catch (err) {
      return reply({ ok: false, error: 'Gmail could not send: ' + err.message });
    }
    return reply({ ok: true, remaining: MailApp.getRemainingDailyQuota() });
  }

  const cal = CalendarApp.getCalendarById(CALENDAR_ID);
  if (!cal) {
    return reply({ ok: false, error: 'Calendar not found. Check CALENDAR_ID in the script.' });
  }
  if (req.action === 'test') {
    return reply({ ok: true, calendar: cal.getName() });
  }
  if (req.action !== 'add' || !req.event) {
    return reply({ ok: false, error: 'Unknown request.' });
  }

  const ev = req.event;
  const title = String(ev.title || '').slice(0, 200);
  const options = { description: String(ev.description || '').slice(0, 4000), location: String(ev.location || '').slice(0, 200) };
  if (!title || !/^\d{4}-\d{2}-\d{2}$/.test(ev.date || '')) {
    return reply({ ok: false, error: 'The event needs a title and a date.' });
  }
  const endDate = /^\d{4}-\d{2}-\d{2}$/.test(ev.end_date || '') ? ev.end_date : ev.date;
  let event;
  if (!ev.start_time) {
    const start = Utilities.parseDate(ev.date, TIME_ZONE, 'yyyy-MM-dd');
    if (endDate === ev.date) {
      event = cal.createAllDayEvent(title, start, options);
    } else {
      const endExclusive = new Date(Utilities.parseDate(endDate, TIME_ZONE, 'yyyy-MM-dd').getTime() + 24 * 3600 * 1000);
      event = cal.createAllDayEvent(title, start, endExclusive, options);
    }
  } else {
    const start = Utilities.parseDate(ev.date + ' ' + ev.start_time, TIME_ZONE, 'yyyy-MM-dd HH:mm');
    const end = ev.end_time
      ? Utilities.parseDate(endDate + ' ' + ev.end_time, TIME_ZONE, 'yyyy-MM-dd HH:mm')
      : new Date(start.getTime() + 3600 * 1000);
    event = cal.createEvent(title, start, end, options);
  }
  return reply({ ok: true, id: event.getId() });
}

function doGet() {
  return reply({ ok: true, message: 'Bayswater Common calendar bridge is running.' });
}

function reply(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
