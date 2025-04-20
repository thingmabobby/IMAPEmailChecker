# IMAPEmailChecker

A PHP class to fetch and process emails from an IMAP mailbox. This class provides functionalities to retrieve emails based on different criteria, check mailbox status, manage read/unread flags, perform custom searches, decode email bodies (including handling inline images), extract attachments, delete and archive emails.

## Purpose

This class is designed to simplify the process of accessing and managing emails via IMAP in PHP. It can be used for various applications such as:

-   **Email Archiving:** Storing emails in a database or other storage for record-keeping.
-   **Automated Email Processing:** Building scripts to analyze incoming emails, trigger actions based on email content, or integrate email data into other systems.
-   **Email Backup Solutions:** Downloading and backing up emails from a mailbox.
-   **Mailbox Monitoring:** Checking for new or unread emails and mailbox status using efficient methods.
-   **Targeted Retrieval:** Searching for specific emails based on various criteria and fetching only those.

The class handles complexities like:

-   **Robust Decoding:** Correctly decodes email bodies and headers in various encodings (e.g., Base64, Quoted-Printable) and normalizes text content to **UTF-8**.
-   **Inline Image Embedding:** Automatically embeds inline images (referenced via `cid:`) within HTML email bodies as Base64 data URIs.
-   **Attachment Extraction:** Provides access to *non-inline* email attachments.
-   **UID Management:** Focuses on using Unique Identifiers (UIDs) for reliable message identification and efficient incremental retrieval.
-   **Status Checks & Flag Management:** Provides methods to check mailbox status efficiently and manage the `\Seen` (read/unread) flag.
-   **Custom Search:** Allows flexible searching using standard IMAP criteria strings.

## Usage

To use the `IMAPEmailChecker` class, you need to have the PHP IMAP extension enabled. You'll first need to establish an IMAP connection using `imap_open()` before instantiating the class.

Here's an extended example covering many common operations:

```php
<?php

declare(strict_types=1);

// Use Composer's autoloader
// Make sure to run 'composer install' in your project directory
require 'vendor/autoload.php';

// Or if not using Composer:
// require 'src/IMAPEmailChecker.php';

use IMAPEmailChecker\IMAPEmailChecker;
use DateTime;

// --- Configuration ---
// IMPORTANT: Store credentials securely (e.g., .env file, environment variables), not directly in code!
$hostname = getenv('IMAP_HOSTNAME') ?: '{your_imap_server:993/imap/ssl}INBOX'; // Example: '{imap.gmail.com:993/imap/ssl}INBOX'
$username = getenv('IMAP_USERNAME') ?: 'your_username@example.com';
$password = getenv('IMAP_PASSWORD') ?: 'your_password';
$archiveFolder = getenv('IMAP_ARCHIVE_FOLDER') ?: 'Archive'; // e.g., '[Gmail]/All Mail' or 'Archived'

// --- Establish Connection ---
echo "Connecting to " . htmlspecialchars($hostname) . "...<br>";
$connection = imap_open($hostname, $username, $password);

if (!$connection) {
    // Display detailed error and exit
    printf("Connection failed: %s <br>\n", htmlspecialchars(imap_last_error() ?: 'Unknown error'));
    exit();
}
echo "Connection successful.<br>";

// --- Instantiate the Class ---
$emailChecker = new IMAPEmailChecker($connection);

// --- Helper Function for Display ---
function displayEmailDetails(array $email): void
{
    $uid = $email['uid'] ?? 'N/A';
    echo "<h4>--- Details for Email UID: {$uid} ---</h4>";
    echo "<ul>";
    echo "<li><b>Subject:</b> " . htmlspecialchars($email['subject'] ?? 'N/A') . "</li>";
    echo "<li><b>From:</b> " . htmlspecialchars($email['from'] ?? 'N/A') . "</li>";
    echo "<li><b>Date:</b> " . htmlspecialchars($email['date'] ?? 'N/A') . "</li>";

    // Display Recipients
    if (!empty($email['to'])) {
        echo "<li><b>To:</b> " . htmlspecialchars(implode(', ', $email['to'])) . "</li>";
    }
    if (!empty($email['cc'])) {
        echo "<li><b>Cc:</b> " . htmlspecialchars(implode(', ', $email['cc'])) . "</li>";
    }
    // BCC is often not available in headers of received mail
    if (!empty($email['bcc'])) {
        echo "<li><b>Bcc (Received):</b> " . htmlspecialchars(implode(', ', $email['bcc'])) . "</li>";
    }

    // Display Body Snippet or Information
    $body = $email['message_body'] ?? '';
    if (!empty($body)) {
        // Avoid outputting raw HTML directly. Show info or a snippet.
        echo "<li><b>Body Length:</b> " . strlen($body) . " characters</li>";
        // Example snippet (be careful with HTML content)
        $snippet = mb_substr(strip_tags($body), 0, 100); // Get first 100 chars of plain text version
        echo "<li><b>Body Snippet:</b> " . htmlspecialchars($snippet) . "...</li>";
        // For full display, consider an iframe or specific rendering logic:
        // echo '<li><b>Body Preview:</b> <details><summary>Click to view body</summary><iframe srcdoc="' . htmlspecialchars($body) . '" style="width:100%; height: 200px; border: 1px solid #ccc;"></iframe></details></li>';
    } else {
        echo "<li><b>Body:</b> (empty or not retrieved)</li>";
    }

    // Display Attachments
    $attachments = $email['attachments'] ?? [];
    if (!empty($attachments)) {
        echo "<li><b>Attachments (" . count($attachments) . "):</b>";
        echo "<ul>";
        foreach ($attachments as $attachment) {
            $filename = htmlspecialchars($attachment['filename'] ?? 'unknown');
            $filesize = isset($attachment['content']) ? round(strlen($attachment['content']) / 1024) : 'N/A'; // Size in KB
            $filetype = htmlspecialchars($attachment['type'] ?? 'unknown');
            echo "<li>{$filename} ({$filesize} KB, Type: {$filetype})</li>";
            // In a real app, you might add a link to download/save the attachment:
            // saveAttachment($uid, $attachment['filename'], $attachment['content']);
        }
        echo "</ul></li>";
    } else {
         echo "<li><b>Attachments:</b> None</li>";
    }

    echo "</ul>";
}


// ========================================================
// --- Example Operations ---
// ========================================================

// 1. Check Mailbox Status (get counts and UIDs)
echo "<h3>1. Mailbox Status:</h3>";
$mailboxStatus = $emailChecker->checkMailboxStatus();
if ($mailboxStatus === false) {
    echo "<p style='color: red;'>Failed to retrieve mailbox status. Error: " . htmlspecialchars(imap_last_error() ?: 'Unknown') . "</p>";
} else {
    // (Display status - same as before)
    echo "<ul>";
    echo "<li>Total Messages: " . $mailboxStatus['total'] . "</li>";
    echo "<li>Highest UID: " . $mailboxStatus['highest_uid'] . "</li>";
    echo "<li>Recent Count: " . count($mailboxStatus['recent_uids']) . "</li>";
    echo "<li>Unseen Count: " . count($mailboxStatus['unseen_uids']) . "</li>";
    echo "</ul>";
}
echo "<hr>";

// 2. Check Unread Emails & Display Details
echo "<h3>2. Processing Unread Emails:</h3>";
$unreadEmails = $emailChecker->checkUnreadEmails(); // Returns array keyed by UID or false
$uidsToMarkRead = [];

if ($unreadEmails === false) {
    echo "<p style='color: red;'>Error checking unread emails. Error: " . htmlspecialchars(imap_last_error() ?: 'Unknown') . "</p>";
} elseif (empty($unreadEmails)) {
    echo "<p>No unread emails found.</p>";
} else {
    echo "<p>Found " . count($unreadEmails) . " unread emails. Processing details...</p>";
    foreach ($unreadEmails as $uid => $email) {
        displayEmailDetails($email); // Use the helper function to show details
        $uidsToMarkRead[] = $uid; // Collect UIDs to mark as read later
    }
    echo "<p>Last UID updated by checkUnreadEmails: " . $emailChecker->lastuid . "</p>";

    // 3. Mark Processed Emails as Read
    if (!empty($uidsToMarkRead)) {
        echo "<h4>Marking " . count($uidsToMarkRead) . " emails as Read:</h4>";
        if ($emailChecker->setMessageReadStatus($uidsToMarkRead, true)) { // true = mark as read
            echo "<p>Successfully marked emails as read.</p>";
        } else {
            echo "<p style='color: red;'>Failed to mark emails as read. Error: " . htmlspecialchars(imap_last_error() ?: 'Unknown') . "</p>";
        }
    }
}
echo "<hr>";

// 4. Check emails since the last known UID (Incremental Check)
echo "<h3>4. Checking New Emails Since Last Run:</h3>";
// In a real app, load this from storage
$lastKnownUID = 0; // <<< Replace with loading logic
echo "<p>Checking for emails newer than UID: " . $lastKnownUID . "</p>";
$emailsSinceLastUID = $emailChecker->checkSinceLastUID($lastKnownUID);
$uidsProcessedInThisRun = [];

if ($emailsSinceLastUID === false) {
    echo "<p style='color: red;'>Error checking emails since last UID. Error: " . htmlspecialchars(imap_last_error() ?: 'Unknown') . "</p>";
} elseif (empty($emailsSinceLastUID)) {
    echo "<p>No new emails found since UID " . $lastKnownUID . ".</p>";
} else {
    echo "<p>Found " . count($emailsSinceLastUID) . " new emails.</p>";
    foreach ($emailsSinceLastUID as $uid => $email) {
        displayEmailDetails($email); // Display details of new emails
        $uidsProcessedInThisRun[] = $uid;
        // Your processing logic here (e.g., save to DB, trigger actions)...
    }
    $newLastUID = $emailChecker->lastuid;
    echo "<p>Last UID updated to: " . $newLastUID . "</p>";
    // IMPORTANT: You should store $newLastUID persistently for the next run
    // save_last_uid_to_storage($newLastUID);
}
echo "<hr>";

// 5. Custom Search & Fetch Specific Messages
echo "<h3>5. Custom Search & Fetch Specific Details:</h3>";
// Example: Find emails with PDF attachments received this month
$currentMonth = date('M-Y'); // e.g., May-2024
$searchCriteria = 'TEXT ".pdf" SINCE "1-' . $currentMonth . '"';
echo "<h4>Searching for: \"{$searchCriteria}\" (UIDs)</h4>";
$pdfEmailUids = $emailChecker->search($searchCriteria);

if ($pdfEmailUids === false) {
    echo "<p style='color: red;'>Search failed. Error: " . htmlspecialchars(imap_last_error() ?: 'Unknown') . "</p>";
} elseif (empty($pdfEmailUids)) {
    echo "<p>No emails found matching criteria.</p>";
} else {
    echo "<p>Found " . count($pdfEmailUids) . " matching UIDs: " . implode(', ', $pdfEmailUids) . ". Fetching details...</p>";

    // 6. Fetch the details ONLY for the messages found by search
    $pdfEmails = $emailChecker->fetchMessagesByIds($pdfEmailUids);

    if (empty($pdfEmails)) {
         echo "<p style='color: orange;'>Could not fetch details for the found UIDs (check logs).</p>";
    } else {
        foreach ($pdfEmails as $uid => $email) {
            displayEmailDetails($email); // Display details for each found email
        }
    }
}
echo "<hr>";


// --- Destructive Operations (Use with extreme caution!) ---
// Get some UIDs to potentially delete/archive (e.g., from the search above)
$uids_to_process = $pdfEmailUids ?? $uidsToMarkRead ?? [];

// 7. Delete an email by UID (Example: Last UID found in search)
if (!empty($uids_to_process)) {
    $uid_to_delete = $uids_to_process[count($uids_to_process)-1];
    echo "<h3>7. Attempting to Delete Email (UID {$uid_to_delete}):</h3>";
    echo "<p><strong>Warning: This action is permanent after expunge!</strong></p>";
    // Uncomment the following lines ONLY if you are sure!
    // $deleteResult = $emailChecker->deleteEmail($uid_to_delete);
    // if ($deleteResult) { echo "<p>Email deleted successfully.</p>"; } else { echo "<p style='color:red;'>Failed to delete. Error: " . htmlspecialchars(imap_last_error() ?: 'Unknown') . "</p>"; }
    echo "<p>(Deletion code is commented out for safety)</p>";
} else {
    echo "<h3>7. Deleting Email:</h3><p>No UIDs available to demonstrate deletion.</p>";
}
echo "<hr>";

// 8. Archive an email by UID (Example: First UID found in search)
if (!empty($uids_to_process)) {
    $uid_to_archive = $uids_to_process[0];
    echo "<h3>8. Attempting to Archive Email (UID {$uid_to_archive}) to '{$archiveFolder}':</h3>";
    echo "<p><strong>Warning: This moves the email and expunges the original! Ensure folder exists.</strong></p>";
    // Uncomment the following lines ONLY if you are sure!
    // $archiveResult = $emailChecker->archiveEmail($uid_to_archive, $archiveFolder);
    // if ($archiveResult) { echo "<p>Email archived successfully.</p>"; } else { echo "<p style='color:red;'>Failed to archive. Error: " . htmlspecialchars(imap_last_error() ?: 'Unknown') . "</p>"; }
    echo "<p>(Archival code is commented out for safety)</p>";
} else {
    echo "<h3>8. Archiving Email:</h3><p>No UIDs available to demonstrate archival.</p>";
}
echo "<hr>";

// --- Clean up ---
echo "Closing connection.<br>";
imap_close($connection);

?>
```


### Available Methods

Methods that fetch full email details (`check*` methods, `fetchMessagesByIds`) return data in an array format keyed by **UID**. Methods that only find identifiers (`search`) return arrays of identifiers. Methods that manage flags or mailbox state return boolean or status arrays.

*   **`checkMailboxStatus()`**:

    Checks the status of the current mailbox efficiently without fetching email bodies/headers.

    *   **Returns:** `array|bool` - An associative array with keys:
        *   `total` (`int`): Total number of messages.
        *   `highest_uid` (`int`): The highest UID currently in the mailbox (0 if empty).
        *   `recent_uids` (`array<int>`): UIDs of messages with the `\Recent` flag.
        *   `unseen_uids` (`array<int>`): UIDs of messages with the `\Unseen` flag.
        Returns `false` on failure of any underlying IMAP operation.


*   **`search(string $criteria, bool $returnUids = true)`**:

    Performs a search using custom IMAP criteria and returns only the matching identifiers.

    *   **Parameters:**
        *   `$criteria` (`string`): The IMAP search criteria string (e.g., `FROM "x"`, `SUBJECT "y"`).
        *   `$returnUids` (`bool`, optional): `true` (default) to return UIDs, `false` for sequence numbers.
    *   **Returns:** `array|false` - An array of integer UIDs or sequence numbers, sorted numerically. Empty array if no match. `false` on search failure or empty criteria.


*   **`fetchMessagesByIds(array $identifiers, bool $isUid = true)`**:

    Fetches and processes the full details for specific messages identified by UID or sequence number. Designed to be used after `search` or if you have a list of specific messages to retrieve. **Does not** update the class's main `$messages` or `$lastuid` properties.

    *   **Parameters:**
        *   `$identifiers` (`array<int>`): Array of UIDs or sequence numbers.
        *   `$isUid` (`bool`, optional): `true` (default) if `$identifiers` are UIDs, `false` if sequence numbers.
    *   **Returns:** `array` - An associative array where keys are the requested identifiers (or the actual UID if found) and values are the processed message data arrays (see "Message Array Structure"). Omits messages that couldn't be processed.


*   **`checkAllEmail()`**:

    Retrieves *all* emails from the connected mailbox. **Use with caution on large mailboxes.** Stores results in `$messages` property.

    *   **Returns:** `array` - An associative array (keyed by UID) of processed email details. Empty array if no emails found. Updates `$lastuid`.


*   **`checkSinceDate(DateTime $date)`**:

    Retrieves emails received on or after the specified date. Stores results in `$messages` property.

    *   **Parameters:** `$date` (`DateTime`)
    *   **Returns:** `array|bool` - Associative array of emails (keyed by UID). `false` on search failure, empty array if none found. Updates `$lastuid`.


*   **`checkSinceLastUID(int $uid)`**:

    Retrieves emails with a UID **greater than** the provided `$uid`. **Recommended for incremental fetching.** Stores results in `$messages` property.

    *   **Parameters:** `$uid` (`int`) - Last known UID (use 0 for first check).
    *   **Returns:** `array|bool` - Associative array of emails (keyed by UID). `false` on search failure, empty array if none found. Updates `$lastuid` only if new emails are found.


*   **`checkUnreadEmails()`**:

    Retrieves emails currently marked with the `\Unseen` flag. Stores results in `$messages` property.

    *   **Returns:** `array|bool` - Associative array of unread emails (keyed by UID). `false` on search failure, empty array if none found. Updates `$lastuid`.


*   **`setMessageReadStatus(array $uids, bool $markAsRead)`**:

    Sets or clears the `\Seen` (read/unread) flag for the specified UIDs.

    *   **Parameters:**
        *   `$uids` (`array<int>`): Array of UIDs to modify.
        *   `$markAsRead` (`bool`): `true` to mark as read (`\Seen`), `false` to mark as unread (clear `\Seen`).
    *   **Returns:** `bool` - `true` on success, `false` on failure or if any UID was invalid.


*   **`deleteEmail(int $uid)`**:

    Deletes an email by UID (marks for deletion and expunges). **Permanent action! Use with caution.**

    *   **Parameters:** `$uid` (`int`)
    *   **Returns:** `bool` - `true` on success, `false` on failure.


*   **`archiveEmail(int $uid, string $archiveFolder = 'Archive')`**:

    Moves an email by UID to a specified folder and expunges the original. **Use with caution.** Ensure the target folder exists.

    *   **Parameters:**
        *   `$uid` (`int`)
        *   `$archiveFolder` (`string`, optional)
    *   **Returns:** `bool` - `true` on success, `false` on failure.


### Public Properties

*   **`$lastuid`**:
    *   Type: `int`
    *   Description: After calling `checkAllEmail()`, `checkSinceDate()`, `checkSinceLastUID()`, or `checkUnreadEmails()`, this property holds the **UID** of the *last* (highest UID) email processed during that specific call. Store this between runs for efficient use with `checkSinceLastUID()`. Initialized to `0`. **Note:** `fetchMessagesByIds` does *not* update this property.

*   **`$messages`**:
    *   Type: `array`
    *   Description: An associative array containing the email messages fetched by the last successful call to a `check*` method that retrieves full message details (e.g., `checkAllEmail`, `checkUnreadEmails`). The array is keyed by the message **UID**. **Note:** `fetchMessagesByIds` populates its own return value but does *not* update this property.


### Message Array Structure

Each email message returned by `check*` methods or `fetchMessagesByIds` is an associative array with the following keys:

*   **`uid`**: (`int`) - The unique identifier (UID) of the message in the mailbox (persistent).
*   **`message_number`**: (`int`) - The sequence number of the message (may not be persistent).
*   **`message_id`**: (`string|null`) - The unique Message-ID header, angle brackets trimmed. `null` if not present.
*   **`subject`**: (`string`) - The decoded, UTF-8 subject. Empty string if not present.
*   **`message_body`**: (`string`) - The decoded, UTF-8 body (HTML preferred), with inline images embedded. Empty string if empty or decoding failed.
*   **`date`**: (`string|null`) - The raw date string from the header. `null` if not present.
*   **`datetime`**: (`DateTime|null`) - A `DateTime` object for the email's date. `null` if parsing failed.
*   **`fromaddress`**: (`string`) - The decoded sender email address. Empty string if parsing failed.
*   **`from`**: (`string`) - The decoded sender friendly name and address.
*   **`to`**: (`array`) - Array of decoded "To" addresses.
*   **`tocount`**: (`int`) - Count of "To" addresses.
*   **`cc`**: (`array`) - Array of decoded "CC" addresses.
*   **`cccount`**: (`int`) - Count of "CC" addresses.
*   **`bcc`**: (`array`) - Array of decoded "BCC" addresses (often empty).
*   **`bcccount`**: (`int`) - Count of "BCC" addresses.
*   **`attachments`**: (`array`) - Array of **non-inline** attachments. Each is an array:
    *   **`filename`**: (`string`) - Decoded filename.
    *   **`content`**: (`string`) - Raw (decoded) attachment content.
    *   **`type`**: (`string`) - Decoded MIME subtype (e.g., `jpeg`, `pdf`).
*   **`bid`**: (`int|null`) - Number extracted from subject `#N`, or `null`.
*   **`unseen`**: (`bool`) - `true` if `\Unseen` flag was set *at the time the message details were fetched*. Check `checkMailboxStatus` for current status.


### Requirements

*   PHP version 8.0 or higher.
*   PHP IMAP extension enabled (`ext-imap`).
*   PHP Multibyte String extension enabled (`ext-mbstring`).


### Notes

*   **Error Handling:** Check return values (`false`) for methods that interact with IMAP. Use `imap_last_error()` for details. Internal errors are logged via `error_log`.
*   **Performance:** `checkMailboxStatus` and `search` are efficient for checking status or finding specific message IDs. `checkSinceLastUID` is best for polling. Fetching full details (`check*`, `fetchMessagesByIds`) is slower. Avoid `checkAllEmail` on large mailboxes.
*   **Security:** **Never hardcode credentials.** Use environment variables or secure configuration methods. Ensure you connect via SSL/TLS.
*   **UID Focus:** The class prioritizes UIDs for reliability.
*   **State Management:** Be aware that only the main `check*` methods update the `$messages` and `$lastuid` properties. `fetchMessagesByIds` returns its results directly without altering the main class state.
*   **UTF-8:** Textual content is normalized to UTF-8.
*   **Resource Management:** The destructor closes the connection. Explicit `imap_close($connection)` after use is still good practice.
*   **Flags:** The `unseen` value in the message array is a snapshot. Use `checkMailboxStatus` or `setMessageReadStatus` for current flag states. `\Recent` flag behavior varies by server.