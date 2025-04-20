# IMAPEmailChecker

A PHP class to fetch and process emails from an IMAP mailbox. This class provides functionalities to retrieve emails based on different criteria, decode email bodies (including handling inline images), extract attachments, delete and archive emails.

## Purpose

This class is designed to simplify the process of accessing and managing emails via IMAP in PHP. It can be used for various applications such as:

-   **Email Archiving:** Storing emails in a database or other storage for record-keeping.
-   **Automated Email Processing:** Building scripts to analyze incoming emails, trigger actions based on email content, or integrate email data into other systems.
-   **Email Backup Solutions:** Downloading and backing up emails from a mailbox.

The class handles complexities like:

-   **Robust Decoding:** Correctly decodes email bodies and headers in various encodings (e.g., Base64, Quoted-Printable) and normalizes text content to **UTF-8**, handling specified charsets or attempting auto-detection.
-   **Inline Image Embedding:** Automatically embeds inline images (referenced via `cid:`) within HTML email bodies as Base64 data URIs for easy display.
-   **Attachment Extraction:** Provides access to *non-inline* email attachments with their filenames, content, and types.
-   **UID Management:** Focuses on using Unique Identifiers (UIDs) for reliable message identification and supports fetching new emails since the last processed email using UIDs for efficient incremental retrieval.

## Usage

To use the `IMAPEmailChecker` class, you need to have the PHP IMAP extension enabled. You'll first need to establish an IMAP connection using `imap_open()` before instantiating the class.

Here's a basic example of how to use the class:

```php
<?php

declare(strict_types=1);

require 'IMAPEmailChecker.php'; // Adjust path if necessary

use IMAPEmailChecker\IMAPEmailChecker;
use DateTime;

// IMAP connection details - Replace with your actual server details
// Ensure you use the correct flags (e.g., /ssl, /tls, /novalidate-cert)
$hostname = '{your_imap_server:993/imap/ssl}INBOX'; // Example for Gmail with SSL
$username = 'your_username@example.com';
$password = 'your_password';

// Establish IMAP connection
// Consider adding OP_READONLY if you only intend to read messages initially
$connection = imap_open($hostname, $username, $password);

if (!$connection) {
    // It's crucial to handle connection errors robustly
    echo "Connection failed: " . imap_last_error() . "\n";
    // You might want to log this error instead of echoing
    exit();
}

// Instantiate the IMAPEmailChecker class
$emailChecker = new IMAPEmailChecker($connection);

// --- Example Operations ---

// 1. Check all emails in the inbox
echo "<h3>All Emails:</h3>";
$allEmails = $emailChecker->checkAllEmail(); // Returns array keyed by UID
if (empty($allEmails)) {
    echo "<p>No emails found.</p>";
} else {
    echo "<p>Found " . count($allEmails) . " emails.</p>";
    echo "<pre>";
    // print_r($allEmails); // Be careful printing large arrays
    // Example: Print subjects of all emails
    foreach ($allEmails as $uid => $email) {
        echo "UID: {$uid} - Subject: " . htmlspecialchars($email['subject'] ?? 'N/A') . "<br>";
    }
    echo "</pre>";
    echo "<p>Last UID processed: " . $emailChecker->lastuid . "</p>"; // Store this if needed
}
echo "<hr>";

// 2. Check emails since a specific date
echo "<h3>Emails Since Date:</h3>";
$sinceDate = new DateTime('2024-05-25'); // Example date
$emailsSinceDate = $emailChecker->checkSinceDate($sinceDate); // Returns array keyed by UID or false

if ($emailsSinceDate === false) {
    echo "<p>Error checking emails since date. IMAP Error: " . imap_last_error() . "</p>";
} elseif (empty($emailsSinceDate)) {
    echo "<p>No emails found since " . $sinceDate->format('Y-m-d') . ".</p>";
} else {
    echo "<p>Found " . count($emailsSinceDate) . " emails since " . $sinceDate->format('Y-m-d') . ".</p>";
    echo "<pre>";
    // print_r($emailsSinceDate);
    foreach ($emailsSinceDate as $uid => $email) {
        echo "UID: {$uid} - Subject: " . htmlspecialchars($email['subject'] ?? 'N/A') . "<br>";
    }
    echo "</pre>";
    echo "<p>Last UID updated to: " . $emailChecker->lastuid . "</p>"; // Store this if needed
}
echo "<hr>";

// 3. Check emails since the last known UID (Incremental Check)
echo "<h3>Emails Since Last UID:</h3>";

// --- How to manage last UID ---
// Scenario 1: First run, or no stored UID. Use 0.
$lastKnownUID = 0; // Or load from your storage (database, file, etc.)
// $lastKnownUID = load_last_uid_from_storage();

echo "<p>Checking for emails newer than UID: " . $lastKnownUID . "</p>";

$emailsSinceLastUID = $emailChecker->checkSinceLastUID($lastKnownUID); // Returns array keyed by UID or false

if ($emailsSinceLastUID === false) {
    echo "<p>Error checking emails since last UID. IMAP Error: " . imap_last_error() . "</p>";
} elseif (empty($emailsSinceLastUID)) {
    echo "<p>No new emails found since UID " . $lastKnownUID . ".</p>";
    // $emailChecker->lastuid might still be $lastKnownUID if nothing was found
    echo "<p>Current last UID remains: " . $emailChecker->lastuid . "</p>";
} else {
    echo "<p>Found " . count($emailsSinceLastUID) . " new emails.</p>";
    echo "<pre>";
    // Process the new emails
    foreach ($emailsSinceLastUID as $uid => $email) {
        echo "Processing New Email UID: {$uid} - Subject: " . htmlspecialchars($email['subject'] ?? 'N/A') . "<br>";
        // Add your processing logic here (e.g., save to DB, trigger actions)
    }
    echo "</pre>";
    $newLastUID = $emailChecker->lastuid;
    echo "<p>Last UID updated to: " . $newLastUID . "</p>";
    // IMPORTANT: Store the new last UID for the next run
    // save_last_uid_to_storage($newLastUID);
}
echo "<hr>";

// --- Destructive Operations (Use with extreme caution!) ---

// Get a list of UIDs to potentially delete/archive (e.g., from the last check)
$uids_to_process = array_keys($emailsSinceLastUID ?? []); // Get UIDs from the last successful check

// 4. Delete an email by UID (Example: Delete the first new email found)
if (!empty($uids_to_process)) {
    $uid_to_delete = $uids_to_process[0]; // Example: target the first UID
    echo "<h3>Attempting to Delete Email (UID {$uid_to_delete}):</h3>";
    echo "<p><strong>Warning: This action is permanent after expunge!</strong></p>";

    // Uncomment the following lines ONLY if you are sure!
    // $deleteResult = $emailChecker->deleteEmail($uid_to_delete);
    // if ($deleteResult) {
    //     echo "<p>Email with UID {$uid_to_delete} deleted successfully.</p>";
    // } else {
    //     echo "<p>Failed to delete email with UID {$uid_to_delete}.</p>";
    //     echo "<p>Error: " . imap_last_error() . "</p>";
    // }
    echo "<p>(Deletion code is commented out for safety)</p>";

} else {
    echo "<h3>Deleting Email:</h3>";
    echo "<p>No UIDs available from the last check to demonstrate deletion.</p>";
}
echo "<hr>";


// 5. Archive an email by UID (Example: Archive the first new email found)
// Make sure the 'Archive' folder exists on your mail server, or change the name.
if (!empty($uids_to_process)) {
    $uid_to_archive = $uids_to_process[0]; // Example: target the first UID (if deletion wasn't performed)
    $archiveFolder = 'Archive'; // Or 'Archived', 'Processed', etc. - check your mailbox folders
    echo "<h3>Attempting to Archive Email (UID {$uid_to_archive}) to '{$archiveFolder}':</h3>";
    echo "<p><strong>Warning: This moves the email and expunges the original!</strong></p>";

    // Uncomment the following lines ONLY if you are sure!
    // $archiveResult = $emailChecker->archiveEmail($uid_to_archive, $archiveFolder);
    // if ($archiveResult) {
    //     echo "<p>Email with UID {$uid_to_archive} archived successfully to '{$archiveFolder}'.</p>";
    // } else {
    //     echo "<p>Failed to archive email with UID {$uid_to_archive}.</p>";
    //     echo "<p>Error: " . imap_last_error() . "</p>";
    // }
     echo "<p>(Archival code is commented out for safety)</p>";
} else {
    echo "<h3>Archiving Email:</h3>";
    echo "<p>No UIDs available from the last check to demonstrate archival.</p>";
}
echo "<hr>";


// Explicitly close the connection (optional, as destructor does it, but good practice)
// imap_close($connection);
// echo "<p>IMAP connection closed explicitly.</p>";
?>
```
  

### Available Methods

All `check*` methods return data in an array format keyed by **UID**, except for `deleteEmail()` and `archiveEmail()` which return boolean values indicating success or failure. The results are also stored in the public `$messages` property after each `check*` call.

*   **`checkAllEmail()`**:

    Retrieves all emails from the connected mailbox. Be cautious when using this method on very large inboxes as it may consume significant resources and time.

    *   **Returns:** `array` - An associative array where keys are message **UIDs** and values are arrays containing email details (see "Message Array Structure" below). Returns an empty array if no emails are found. Updates the `$lastuid` property to the highest UID found.

*   **`checkSinceDate(DateTime $date)`**:

    Searches for emails received on or after the specified date (`SINCE` criteria).

    *   **Parameters:**
        *   `$date` (`DateTime` object): The date from which to start searching for emails.
    *   **Returns:** `array|bool` - An associative array of emails (keyed by **UID**) received since the given date (same structure as `checkAllEmail()`). Returns `false` on search failure (check `imap_last_error()`), or an empty array if no emails are found since the date. Updates the `$lastuid` property to the highest UID found *among the matching emails*.

*   **`checkSinceLastUID(int $uid)`**:

    Retrieves emails with a UID **greater than** the provided `$uid`. This is the recommended method for fetching only new emails since the last check. To use this effectively, you should store the `$lastuid` property after each successful call and use it as the `$uid` parameter for the next call.

    *   **Parameters:**
        *   `$uid` (`int`): The last known UID. Use `0` for the initial check if you don't have a previous UID.
    *   **Returns:** `array|bool` - An associative array of emails (keyed by **UID**) received since the given UID (same structure as `checkAllEmail()`). Returns `false` on search failure (check `imap_last_error()`), or an empty array if no new emails are found. Updates the `$lastuid` property to the highest UID found *among the newly fetched emails*. If no new emails are found, `$lastuid` remains unchanged.

*   **`deleteEmail(int $uid)`**:

    Deletes an email from the mailbox by its UID. This marks the email for deletion and then **expunges** the mailbox, permanently removing it. **Use with extreme caution.**

    *   **Parameters:**
        *   `$uid` (`int`): The **UID** of the email to delete.
    *   **Returns:** `bool` - `true` on successful deletion and expunge, `false` on failure (e.g., failed to mark for delete or failed to expunge). Check `imap_last_error()` on failure.

*   **`archiveEmail(int $uid, string $archiveFolder = 'Archive')`**:

    Archives an email by moving it to a specified folder using its UID and then **expunges** the current mailbox to remove the original. The default archive folder is "Archive". Ensure the target folder exists on the server. **Use with caution.**

    *   **Parameters:**
        *   `$uid` (`int`): The **UID** of the email to archive.
        *   `$archiveFolder` (`string`, optional): The name of the folder to move the email to. Defaults to `'Archive'`. Use the correct name as it appears on your mail server.
    *   **Returns:** `bool` - `true` on successful move and expunge, `false` on failure (e.g., move failed, folder doesn't exist, or expunge failed). Check `imap_last_error()` on failure.

### Public Properties

*   **`$lastuid`**:
    *   Type: `int`
    *   Description: After calling `checkAllEmail()`, `checkSinceDate()`, or `checkSinceLastUID()`, this property will be updated to the **UID** of the *last* (highest UID) email processed during that call. You should typically store this value between script runs and use it in subsequent calls to `checkSinceLastUID()` to efficiently retrieve only new emails. Initialized to `0`.

*   **`$messages`**:
    *   Type: `array`
    *   Description: An associative array containing the email messages fetched by the last successful `check*` method call. The array is keyed by the message **UID**. The structure of each message value within this array is described below in "Message Array Structure".

### Message Array Structure

Each email message in the `$messages` array (keyed by UID) is an associative array with the following keys:

*   **`uid`**: (`int`) - The unique identifier (UID) of the message in the mailbox (persistent). **This is the key in the `$messages` array.**
*   **`message_number`**: (`int`) - The sequence number of the message in the mailbox (may not be persistent across sessions or after deletions/expunges).
*   **`message_id`**: (`string|null`) - The unique Message-ID header of the email (e.g., `message.id@domain.com`), with angle brackets trimmed. `null` if not present.
*   **`subject`**: (`string`) - The email subject, decoded from MIME and converted to UTF-8. Empty string if not present.
*   **`message_body`**: (`string`) - The main body of the email (decoded and converted to UTF-8), with HTML preferred over plain text when available. Inline images referenced by `cid:` are embedded as Base64 data URIs within the HTML content. Can be an empty string if the body is empty or decoding failed.
*   **`date`**: (`string|null`) - The raw date string from the email header (e.g., `Fri, 24 May 2024 15:53:38 -0400`). `null` if not present.
*   **`datetime`**: (`DateTime|null`) - A `DateTime` object representing the email's date and time, parsed from the header. `null` if the date header was missing or could not be parsed.
*   **`fromaddress`**: (`string`) - The decoded email address of the sender (e.g., `sender@example.com`). Empty string if parsing failed.
*   **`from`**: (`string`) - The decoded friendly name and address of the sender (e.g., `"Sender Name" <sender@example.com>` or just `sender@example.com` if no name).
*   **`to`**: (`array`) - An array of decoded email addresses in the "To" field.
*   **`tocount`**: (`int`) - The number of "To" addresses.
*   **`cc`**: (`array`) - An array of decoded email addresses in the "CC" field.
*   **`cccount`**: (`int`) - The number of "CC" addresses.
*   **`bcc`**: (`array`) - An array of decoded email addresses found in the header's "BCC" field (often empty or not present due to privacy).
*   **`bcccount`**: (`int`) - The number of "BCC" addresses found.
*   **`attachments`**: (`array`) - An array containing **non-inline** attachments found in the email. Each element is an associative array with the following keys:
    *   **`filename`**: (`string`) - The decoded filename of the attachment. Defaults to `unknown_N` if a name cannot be determined.
    *   **`content`**: (`string`) - The raw (decoded) content of the attachment.
    *   **`type`**: (`string`) - The decoded MIME subtype of the attachment (e.g., `jpeg`, `png`, `pdf`).
*   **`bid`**: (`int|null`) - Extracts a number from the subject if it matches the pattern `#N` (where N is one or more digits). Returns the extracted number as an `int`, or `null` if no match is found. (Originally for ticket system testing).
*   **`unseen`**: (`bool`) - `true` if the email is marked as `\Unseen` or if it's marked as `\Recent` but not `\Seen`. `false` otherwise.

### Requirements

*   PHP version 8.0 or higher (due to use of property promotion, `match` expressions, etc.)
*   PHP IMAP extension enabled. You may need to install and enable it if it's not already part of your PHP installation (e.g., `sudo apt-get install php8.x-imap` on Debian/Ubuntu or using PECL).

### Notes

*   **Error Handling:** The class includes some internal `error_log` calls but primarily relies on returning `false` from methods upon failure. Your calling script should always check the return values of methods like `checkSinceDate`, `checkSinceLastUID`, `deleteEmail`, and `archiveEmail`. Use `imap_last_error()` and `imap_errors()` after a failure to get detailed IMAP-specific error messages for debugging.
*   **Performance:** For very large mailboxes, `checkAllEmail()` can be slow and memory-intensive. Using `checkSinceLastUID()` with a stored `$lastuid` is highly recommended for regular checks. Fetching bodies and attachments is inherently slower than just fetching headers/overviews.
*   **Security:** Always handle your IMAP credentials securely. Avoid hardcoding passwords directly in scripts. Use environment variables, secure configuration files, or secrets management tools. Ensure your connection uses SSL/TLS (`/ssl` or `/tls` flag in hostname) unless you have a specific reason not to.
*   **UID Focus:** The class primarily operates using UIDs, which are generally persistent identifiers for messages within a mailbox folder, unlike sequence numbers which can change. Methods that modify the mailbox (`deleteEmail`, `archiveEmail`) require UIDs.
*   **UTF-8:** The class attempts to normalize textual content (subjects, bodies, names, filenames) to UTF-8 for consistent handling.
*   **Resource Management:** The class includes a destructor (`__destruct()`) that attempts to close the IMAP connection resource (`imap_close()`). However, explicitly calling `imap_close($connection)` on the original connection resource after you are finished with the `IMAPEmailChecker` object is still recommended for predictable resource cleanup, especially in long-running scripts or complex applications.