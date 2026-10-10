## HOA Announcements Workflow — Next Steps & Context

**Session Date:** 2026-10-10  
**Project:** Bayswater Common HOA announcements system  
**Repo:** BCHOA/bayswatercommon

---

## ✅ Completed Work

- [x] Home page (`index.html`) updated with "Latest Announcements" panel
- [x] Announcements display sorted by start date (earliest first)
- [x] Announcement JSON structure created (`data/announcements.json`)
- [x] Admin dashboard stub created (`admin/dashboard.html`)
- [x] Whitelist/admin file created (`data/whitelist.json`) with yearly expiry (May 1)
- [x] All core permission issues resolved — repo writes are working

---

## 🔧 Architecture Overview

**Data Files:**
- `data/announcements.json` — announcement entries with `id`, `title`, `text`, `start_date`, `expiry_date`, `created_by_email`, `created_at`, `source`
- `data/whitelist.json` — admin entries with `email`, `password_hash` (SHA-256), `role`, `expires_on` (format: `YYYY-05-01`)

**UI Files:**
- `index.html` — home page with announcements panel (loads from JSON, filters active entries)
- `admin/dashboard.html` — password-protected dashboard for managing announcements and whitelist

**Email Workflow (Still Stubs):**
- `.github/workflows/poll-announcements.yml` — GitHub Action that runs daily at 9 AM; polls `hoa-announcements@...` inbox via IMAP
- `scripts/process-announcement-email.js` — parses email, validates whitelist, commits to `announcements.json`
- `scripts/send-board-notice.js` — sends rejection/failure notices to `hoaboard@bayswatercommon.org` via Gmail API

---

## 📋 Immediate Next Steps (In Order)

### 1. **Create GitHub Action Workflow File** (`.github/workflows/poll-announcements.yml`)
   - Trigger: daily at 9 AM UTC (adjust cron as needed)
   - Job:
     - Check out repo
     - Set up Node.js
     - Install `imap-simple` package
     - Run `scripts/process-announcement-email.js` with these environment variables:
       - `IMAP_HOST` (GitHub Secret)
       - `IMAP_PORT` (GitHub Secret)
       - `IMAP_USER` (GitHub Secret) — `hoa-announcements@...` email
       - `IMAP_PASSWORD` (GitHub Secret) — email app password or OAuth
       - `BOARD_EMAIL` (GitHub Secret) — `hoaboard@bayswatercommon.org`
       - `GMAIL_CLIENT_ID` (GitHub Secret)
       - `GMAIL_CLIENT_SECRET` (GitHub Secret)
       - `GMAIL_REFRESH_TOKEN` (GitHub Secret)
     - If credentials are stubs (not configured), skip with message: "STUB: IMAP credentials not configured yet"
     - On success, commit any changes to `data/announcements.json` and push

### 2. **Create Email Parser Script** (`scripts/process-announcement-email.js`)
   - Load `data/whitelist.json` and `data/announcements.json`
   - Email format: `ACTION START_DATE EXPIRY_DATE TEXT`
     - Example: `ADD 2025-03-01 2025-03-15 Annual meeting on March 15. See calendar for details.`
     - Actions: `ADD`, `CHANGE`, `DELETE`
     - Dates: `YYYY-MM-DD` format
     - Text: plain text or HTML (links, bold, etc. allowed)
   - Validation:
     - Check sender email is in `data/whitelist.json`
     - Check admin entry is not expired (`expires_on` >= today)
     - Parse action, dates, and text
     - Validate date format and that both dates are valid
     - If any validation fails, call `send-board-notice.js` with rejection reason
   - On success:
     - Generate new ID (max ID + 1)
     - Create announcement object with `created_by_email` set to sender
     - Add to `announcements.json` array
     - Sort by `start_date`
   - STUB comment: "Replace IMAP fetch logic when email account and credentials are configured"

### 3. **Create Board Notification Script** (`scripts/send-board-notice.js`)
   - Load whitelist and validate sender
   - Check if Gmail OAuth credentials are configured (look for `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REFRESH_TOKEN`)
   - If credentials are NOT configured:
     - Log to console: "[STUB] Gmail OAuth not configured. Board notice would be sent to: hoaboard@..."
     - Exit gracefully
   - When credentials ARE configured (later):
     - Use Gmail API to send email from `hoa-announcements@...` to `hoaboard@...`
     - Subject: "HOA announcement rejected" (or other action)
     - Body: Include sender, reason for rejection, and any details
   - STUB comment: "Replace with real Gmail API OAuth flow when ready"

### 4. **Update Admin Dashboard** (`admin/dashboard.html`)
   - Current state: static HTML that loads JSON files and displays in browser
   - Needs update: add login/password validation against `data/whitelist.json`
     - Load whitelist on page load
     - Prompt for email and password
     - Hash password with SHA-256 and compare to stored hash
     - Check expiry date (`expires_on`)
     - If expired, show message: "This admin entry has expired. It must be renewed before the next May 1 cycle."
   - Download buttons for:
     - `announcements.json` — user edits in browser and downloads
     - `whitelist.json` — user downloads and commits manually
   - Note in UI: "Changes must be committed to GitHub manually. Commit to main and GitHub Pages updates in seconds."

### 5. **Set Up GitHub Secrets** (Repo Settings → Secrets)
   - `IMAP_HOST` — e.g., `imap.gmail.com`
   - `IMAP_PORT` — e.g., `993`
   - `IMAP_USER` — `hoa-announcements@bayswatercommon.org`
   - `IMAP_PASSWORD` — Gmail app-specific password (NOT main password)
   - `BOARD_EMAIL` — `hoaboard@bayswatercommon.org`
   - `GMAIL_CLIENT_ID` — from Google Cloud Console OAuth app
   - `GMAIL_CLIENT_SECRET` — from Google Cloud Console OAuth app
   - `GMAIL_REFRESH_TOKEN` — OAuth refresh token (will need one-time setup)

### 6. **Create Setup Documentation** (`SETUP.md`)
   - How to generate SHA-256 password hashes for `data/whitelist.json`
   - How to set up Gmail API OAuth on Google Cloud Console
   - How to generate app-specific IMAP password on Gmail
   - How to add secrets to GitHub repository settings
   - How to test the workflow (manually trigger or wait for daily 9 AM run)
   - Email format examples and how to send announcement updates

---

## ⚠️ Important Constraints & Rules

1. **Admin Expiry:** Every May 1, all admins in `data/whitelist.json` expire. Must be manually renewed and committed.
2. **No Archive:** Expired announcements stay in JSON but are not displayed. They can be manually removed if file gets too large.
3. **No Paid Services:** Everything uses free GitHub Actions, free Gmail API quota, free IMAP.
4. **Email Format is Strict:** Parser will reject anything that doesn't match `ACTION DATE DATE TEXT` format.
5. **Rejected Emails:** Invalid whitelist, format errors, or expired admins → notification sent to `hoaboard@...`
6. **Immediate Commit:** Valid announcements are committed immediately to `main` branch.
7. **Password Hashing:** All passwords in `whitelist.json` must be SHA-256 hashes (use online tool or Node script to generate).

---

## 🔑 Key Files & Paths

```
BCHOA/bayswatercommon/
├── index.html                                 ✅ done
├── admin/
│   └── dashboard.html                         ✅ done (needs login logic)
├── data/
│   ├── announcements.json                     ✅ done
│   └── whitelist.json                         ✅ done (stub passwords)
├── scripts/
│   ├── process-announcement-email.js          ⏳ TODO
│   └── send-board-notice.js                   ⏳ TODO
├── .github/workflows/
│   └── poll-announcements.yml                 ⏳ TODO
└── SETUP.md                                   ⏳ TODO
```

---

## 🚀 Testing Checklist (When Workflow is Complete)

- [ ] Manually trigger GitHub Action workflow
- [ ] Verify Action runs and logs show proper IMAP stub message
- [ ] Send test email to `hoa-announcements@...` from whitelisted address with proper format
- [ ] Verify Action picks up email and commits to `announcements.json`
- [ ] Verify announcement appears on home page `index.html`
- [ ] Verify announcement expires on `expiry_date`
- [ ] Send test email from non-whitelisted address — verify rejection notice sent to `hoaboard@...`
- [ ] Test with malformed email (wrong date format, missing text, etc.) — verify rejection
- [ ] Test admin dashboard login with password in whitelist
- [ ] Test expired admin login — verify rejection message

---

## 📞 Resume Instructions

When you return and context is lost:
1. Paste this entire document into the conversation
2. Say: "Resume from NEXT STEPS list — continue with step 1 (Create GitHub Action Workflow)"
3. I will read the architecture and checklist and pick up exactly where we left off without restarting

---

**Last Updated:** 2026-10-10 21:12 UTC  
**Status:** Ready for email workflow implementation
