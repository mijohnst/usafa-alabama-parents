// Google Apps Script — paste this into your Apps Script editor and deploy as a Web App
// Setup steps at the bottom of this file.

var SHEET_ID = '1Kx115E854OWVS5wuhL0nGvq8VcpBxVdeEGJpspHvjuU';

function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);

    var sheet = SpreadsheetApp.openById(SHEET_ID).getActiveSheet();

    sheet.appendRow([
      data.last,
      data.first,
      data.dob,
      data.real_id_state,
      data.real_id_number,
      data.dod_id_holder,
      data.us_citizen,
      data.cadet_name,
      data.affiliation,
      data.phone,
      data.email,
      new Date()          // timestamp of submission
    ]);

    return ContentService
      .createTextOutput(JSON.stringify({ success: true }))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (err) {
    return ContentService
      .createTextOutput(JSON.stringify({ success: false, message: err.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

/*
SETUP INSTRUCTIONS
==================
1. Open your Google Sheet:
   https://docs.google.com/spreadsheets/d/1Kx115E854OWVS5wuhL0nGvq8VcpBxVdeEGJpspHvjuU/edit

2. Make sure row 1 has these column headers in this exact order:
   LAST | FIRST | DATE OF BIRTH | REAL ID STATE | REAL ID NUMBER (DRIVER'S LICENSE) |
   DOD ID HOLDER | US CITIZEN | CADET NAME | AFFILIATION WITH CADET | PHONE | EMAIL | SUBMITTED

3. In the sheet menu, click Extensions > Apps Script.

4. Delete any existing code in the editor and paste ALL of this file's contents in.

5. Click Save (floppy disk icon).

6. Click Deploy > New deployment.
   - Type: Web app
   - Description: Sendoff Registration Handler
   - Execute as: Me
   - Who has access: Anyone
   Click Deploy, then authorize when prompted.

7. Copy the Web App URL shown after deployment.

8. Open sendoff-handler.php and replace YOUR_APPS_SCRIPT_WEB_APP_URL_HERE
   with that URL.

9. Upload sendoff-handler.php to your web host (alabamafalcons.org).
   The .gs file does NOT need to be uploaded — it lives in Google.
*/
