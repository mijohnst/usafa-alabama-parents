// ============================================================
//  USAFA Parents Club - Community Photo Gallery
//  Google Apps Script  (copy this entire file into Apps Script)
// ============================================================
//
//  SETUP STEPS
//  -----------
//  1. Go to https://script.google.com and click "New project"
//  2. Delete any existing code and paste this entire file
//  3. Replace FOLDER_ID below with your Google Drive folder ID
//     (open the folder in Drive — the ID is the long string in
//      the URL after /folders/)
//  4. Click Deploy > New deployment
//       Type: Web app
//       Execute as: Me
//       Who has access: Anyone
//  5. Click Deploy, copy the Web app URL
//  6. Paste that URL into index.html where it says
//     REPLACE_WITH_YOUR_APPS_SCRIPT_URL
//
//  SHARING THE UPLOAD FOLDER WITH CONTRIBUTORS
//  --------------------------------------------
//  - Open the Drive folder
//  - Click Share
//  - Enter each contributor's Gmail address
//  - Set their permission to "Editor" (lets them add/delete photos)
//  - Click Send  — they'll get an email with the folder link
//
//  Photos added to the folder will appear on the website
//  automatically within a few seconds of a page refresh.
// ============================================================

var FOLDER_ID = '1qOFC20iSxWuXPKcRNEucHsSsjc77hXve';

function doGet(e) {
  var folder = DriveApp.getFolderById(FOLDER_ID);
  var files   = folder.getFiles();
  var images  = [];

  while (files.hasNext()) {
    var file = files.next();
    if (file.getMimeType().indexOf('image/') === 0) {
      // Make sure the file is accessible to anyone with the link
      file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
      images.push(file.getId());
    }
  }

  // Shuffle so the order is random on every page load
  for (var i = images.length - 1; i > 0; i--) {
    var j   = Math.floor(Math.random() * (i + 1));
    var tmp = images[i];
    images[i] = images[j];
    images[j] = tmp;
  }

  return ContentService
    .createTextOutput('_photosReady(' + JSON.stringify(images) + ')')
    .setMimeType(ContentService.MimeType.JAVASCRIPT);
}
