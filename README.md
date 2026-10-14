# BayswaterCommon.org

Public website for the Bayswater Common HOA (Suwanee, GA), hosted on GitHub Pages.

- Pages: `index.html` (home + latest announcements), `calendar.html`, `documents.html`, `contact.html`. Documents are in `assets/`.
- **Announcements** are managed by board members at https://admin.bayswatercommon.org/ and shown on the home page, which loads them from `https://admin.bayswatercommon.org/api/announcements.php`.
- The admin site is a small PHP app on the HOA's GoDaddy hosting. Its source is in `_server/admin/` (GitHub Pages does not publish `_` folders). Users, passwords and announcements are stored on the server, outside the web folder, never in this repository.

## Admin site roles
- **Editor**: add, edit and delete announcements; change their own password.
- **Admin**: everything an Editor can do, plus add/remove users, set access end dates, and reset passwords.

## Deploying the admin site
Upload the contents of `_server/admin/` to the `admin.bayswatercommon.org` folder in cPanel File Manager (keep the folder structure, including the `.htaccess` files). The first visit to https://admin.bayswatercommon.org/ asks for the first admin account.
