# BayswaterCommon.org

Public website for the Bayswater Common HOA (Suwanee, GA), hosted on GitHub Pages.

- Pages: `index.html` (home + latest announcements), `calendar.html`, `documents.html`, `contact.html`. Documents are in `assets/`.
- **Announcements** and **meeting minutes** are managed by board members at https://admin.bayswatercommon.org/. The home and calendar pages load announcements from `/api/announcements.php`; the documents page loads minutes (uploaded PDFs, audio, documents and links) from `/api/minutes.php`. Uploaded files live on the admin site under `/files/minutes/`.
- Styles are shared in `css/site.css`; scripts are in `js/`. Bump the `?v=` on those links when they change.
- The admin site is a small PHP app on the HOA's GoDaddy hosting. Its source is in `_server/admin/` (GitHub Pages does not publish `_` folders). Users, passwords and announcements are stored on the server, outside the web folder, never in this repository.

## Admin site roles
- **Editor**: manage announcements and meeting minutes; change their own password.
- **Admin**: everything an Editor can do, plus add/remove users, set access end dates, and reset passwords.

## Deploying the admin site
Upload the contents of `_server/admin/` to the `admin.bayswatercommon.org` folder in cPanel File Manager (keep the folder structure, including the `.htaccess` files). The first visit to https://admin.bayswatercommon.org/ asks for the first admin account.
