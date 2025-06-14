# IMAPEmailChecker

A PHP class to fetch and process emails from an IMAP mailbox. This class provides functionalities to retrieve emails based on different criteria, check mailbox status, manage read/unread flags, perform custom searches, decode email bodies (including handling inline images), extract attachments, delete and archive emails.

## Purpose

This class is designed to simplify the process of accessing and managing emails via IMAP in PHP. It provides robust error handling using exceptions and can be used for various applications such as:

-   **Email Archiving:** Storing emails in a database or other storage for record-keeping.
-   **Automated Email Processing:** Building scripts to analyze incoming emails, trigger actions based on email content, or integrate email data into other systems.
-   **Linking Emails to System Records:** Extracting specific identifiers (like ticket numbers, order IDs, or custom tags) directly from email subjects using a configurable regular expression to associate emails with records in your application (e.g., CRM, helpdesk, order management).
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
-   **Exception-Based Errors:** Uses standard PHP exceptions (`RuntimeException`, `InvalidArgumentException`) to signal errors clearly.


## Installation

Composer:
```bash
composer require thingmabobby/imap-email-checker
```

Standalone:
```bash
require '..path/to/src/IMAPEmailChecker.php'; // Adjust path as needed
```


## Usage
To use the IMAPEmailChecker class, you need to have the PHP IMAP extension enabled.
Here's an extended example covering many common operations, demonstrating the use of try...catch for error handling:


```php
<?php
declare(strict_types=1);

// Use Composer's autoloader
// Make sure to run 'composer install' in your project directory
require 'vendor/autoload.php';

// Or if not using Composer:
// require '..path/to/src/IMAPEmailChecker.php'; // Adjust path

use IMAPEmailChecker\IMAPEmailChecker;
use DateTime;

// --- Configuration ---
// IMPORTANT: Store credentials securely (e.g., .env file, environment variables), not directly in code!
$hostname = getenv('IMAP_HOSTNAME') ?: '{your_imap_server:993/imap/ssl}INBOX';
$username = getenv('IMAP_USERNAME') ?: 'your_username@example.com';
$password = getenv('IMAP_PASSWORD') ?: 'your_password';
$archiveFolder = getenv('IMAP_ARCHIVE_FOLDER') ?: 'Archive';

try {
    // Debug mode enabled (for more verbose logging of non-critical issues)
    $debugMode = true; // Set to true to enable debug logging (defaults to false)
    
    echo "Connecting to " . htmlspecialchars($hostname) . "...<br>";    
    $emailChecker = IMAPEmailChecker::connect($hostname, $username, $password, $debugMode);

    // --- Helper Function for Display ---
    function displayEmailDetails(array $email): void
    {
        $uid = $email['uid'] ?? 'N/A';
        echo "<h4>--- Details for Email UID: {$uid} ---</h4>";
        echo "<ul>";
        echo "<li><b>Subject:</b> " . htmlspecialchars($email['subject'] ?? 'N/A') . "</li>";
        echo "<li><b>From:</b> " . htmlspecialchars($email['from'] ?? 'N/A') . "</li>";
        echo "<li><b>Date:</b> " . htmlspecialchars($email['date'] ?? 'N/A') . "</li>";
        if (!empty($email['to'])) echo "<li><b>To:</b> " . htmlspecialchars(implode(', ', $email['to'])) . "</li>";
        if (!empty($email['cc'])) echo "<li><b>Cc:</b> " . htmlspecialchars(implode(', ', $email['cc'])) . "</li>";

        $body = $email['message_body'] ?? '';
        echo "<li><b>Body Length:</b> " . strlen($body) . " characters</li>";
        $snippet = mb_substr(strip_tags($body), 0, 100);
        echo "<li><b>Body Snippet:</b> " . htmlspecialchars($snippet) . "...</li>";

        $attachments = $email['attachments'] ?? [];
        if (!empty($attachments)) {
            echo "<li><b>Attachments (" . count($attachments) . "):</b><ul>";
            foreach ($attachments as $attachment) {
                $filename = htmlspecialchars($attachment['filename'] ?? 'unknown');
                $filesize = isset($attachment['content']) ? round(strlen($attachment['content']) / 1024) : 'N/A';
                $filetype = htmlspecialchars($attachment['type'] ?? 'unknown');
                echo "<li>{$filename} ({$filesize} KB, Type: {$filetype})</li>";
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

    // 1. Check Mailbox Status
    echo "<h3>1. Mailbox Status:</h3>";
    $mailboxStatus = $emailChecker->checkMailboxStatus(); // Throws on failure
    echo "<ul>";
    echo "<li>Total Messages: " . $mailboxStatus['total'] . "</li>";
    echo "<li>Unseen Count: " . count($mailboxStatus['unseen_uids']) . "</li>";
    echo "<li>Highest UID: " . $mailboxStatus['highest_uid'] . "</li>";
    echo "</ul>";
    echo "<hr>";

    // 2. Check Unread Emails & Display Details
    echo "<h3>2. Processing Unread Emails:</h3>";
    $unreadEmails = $emailChecker->checkUnreadEmails(); // Throws on search failure
    $uidsToMarkRead = [];

    if (empty($unreadEmails)) {
        echo "<p>No unread emails found.</p>";
    } else {
        echo "<p>Found " . count($unreadEmails) . " unread emails. Processing details...</p>";
        // Note: processMessage failures inside checkUnreadEmails are logged & skipped
        foreach ($unreadEmails as $uid => $email) {
            displayEmailDetails($email);
            $uidsToMarkRead[] = $uid;
        }
        echo "<p>Last UID updated by checkUnreadEmails (if any processed): " . $emailChecker->lastuid . "</p>";

        // 3. Mark Processed Emails as Read
        if (!empty($uidsToMarkRead)) {
            echo "<h4>Marking " . count($uidsToMarkRead) . " emails as Read:</h4>";
            $emailChecker->setMessageReadStatus($uidsToMarkRead, true); // Throws on failure
            echo "<p>Successfully marked emails as read.</p>";
        }
    }
    echo "<hr>";

    // 4. Check emails since the last known UID (Incremental Check)
    echo "<h3>4. Checking New Emails Since Last Run:</h3>";
    $lastKnownUID = 0; // <<< Replace with loading logic from persistent storage
    echo "<p>Checking for emails newer than UID: " . $lastKnownUID . "</p>";
    $emailsSinceLastUID = $emailChecker->checkSinceLastUID($lastKnownUID); // Throws on overview failure
    $uidsProcessedInThisRun = [];

    if (empty($emailsSinceLastUID)) {
        echo "<p>No new emails found since UID " . $lastKnownUID . ".</p>";
    } else {
        echo "<p>Found " . count($emailsSinceLastUID) . " new emails.</p>";
        // Note: processMessage failures inside checkSinceLastUID are logged & skipped
        foreach ($emailsSinceLastUID as $uid => $email) {
            displayEmailDetails($email);
            $uidsProcessedInThisRun[] = $uid;
        }
        $newLastUID = $emailChecker->lastuid;
        echo "<p>Last UID updated to: " . $newLastUID . "</p>";
        // IMPORTANT: Store $newLastUID persistently for the next run
        // save_last_uid_to_storage($newLastUID);
    }
    echo "<hr>";

    // 5. Custom Search & Fetch Specific Messages
    echo "<h3>5. Custom Search & Fetch Specific Details:</h3>";
    $currentMonth = date('M-Y');
    $searchCriteria = 'TEXT ".pdf" SINCE "1-' . $currentMonth . '"';
    echo "<h4>Searching for: \"{$searchCriteria}\" (UIDs)</h4>";
    $pdfEmailUids = $emailChecker->search($searchCriteria); // Throws on failure or bad criteria

    if (empty($pdfEmailUids)) {
        echo "<p>No emails found matching criteria.</p>";
    } else {
        echo "<p>Found " . count($pdfEmailUids) . " matching UIDs: " . implode(', ', $pdfEmailUids) . ". Fetching details...</p>";

        // 6. Fetch the details ONLY for the messages found by search
        // Note: fetchMessagesByIds logs & skips individual message processing failures
        $pdfEmails = $emailChecker->fetchMessagesByIds($pdfEmailUids);

        if (empty($pdfEmails)) {
            echo "<p style='color: orange;'>Could not fetch details for any of the found UIDs (check logs).</p>";
        } else {
            echo "<p>Successfully fetched details for " . count($pdfEmails) . " emails:</p>";
            foreach ($pdfEmails as $uid => $email) {
                displayEmailDetails($email);
            }
        }
    }
    echo "<hr>";

    // --- Destructive Operations (Use with extreme caution!) ---
    $uids_to_process = $pdfEmailUids ?? $uidsToMarkRead ?? [];

    // 7. Delete an email by UID (Example: Last UID found)
    if (!empty($uids_to_process)) {
        $uid_to_delete = end($uids_to_process); // Get the last element
        echo "<h3>7. Attempting to Delete Email (UID {$uid_to_delete}):</h3>";
        echo "<p><strong>Warning: This action is permanent!</strong></p>";
        // Uncomment the following line ONLY if you are sure!
        // $emailChecker->deleteEmail($uid_to_delete); // Throws on failure
        // echo "<p>Email UID {$uid_to_delete} deleted successfully.</p>";
        echo "<p>(Deletion code is commented out for safety)</p>";
    } else {
        echo "<h3>7. Deleting Email:</h3><p>No UIDs available to demonstrate deletion.</p>";
    }
    echo "<hr>";

    // 8. Archive an email by UID (Example: First UID found)
    if (!empty($uids_to_process)) {
        $uid_to_archive = reset($uids_to_process); // Get the first element
        echo "<h3>8. Attempting to Archive Email (UID {$uid_to_archive}) to '{$archiveFolder}':</h3>";
        echo "<p><strong>Warning: This moves the email and expunges! Ensure folder '{$archiveFolder}' exists.</strong></p>";
        // Uncomment the following line ONLY if you are sure!
        // $emailChecker->archiveEmail($uid_to_archive, $archiveFolder); // Throws on failure
        // echo "<p>Email UID {$uid_to_archive} archived successfully.</p>";
        echo "<p>(Archival code is commented out for safety)</p>";
    } else {
        echo "<h3>8. Archiving Email:</h3><p>No UIDs available to demonstrate archival.</p>";
    }
    echo "<hr>";

} catch (\InvalidArgumentException $e) {
    // Handle errors related to invalid arguments passed to methods
    echo "<p style='color: purple; font-weight: bold;'>Argument Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    // Log detailed error: error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
} catch (\RuntimeException $e) {
    // Handle errors related to IMAP operations or other runtime issues
    echo "<p style='color: red; font-weight: bold;'>Runtime Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    // Log detailed error: error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
} catch (\Throwable $e) {
    // Catch any other unexpected errors
    echo "<p style='color: darkred; font-weight: bold;'>Unexpected Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    // Log detailed error: error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}

?>
```


### Available Methods

Methods that perform IMAP operations may throw exceptions on failure. Batch processing methods (`check*`, `fetchMessagesByIds`) generally handle failures for *individual* messages internally (logging the error and skipping the message) but will throw exceptions for failures affecting the *entire* batch operation (e.g., the initial search/overview). Action methods (`setMessageReadStatus`, `deleteEmail`, `archiveEmail`) return `void` and throw exceptions on any failure.

*   **`static connect(string $hostname, string $username, string $password, string $mailbox = 'INBOX', int $port = 993, string $flags = '/ssl', int $retries = 0, bool $debug = false, string $bidRegex = '/#\s*(\d+)/')`**:
    *   A static factory method that establishes an IMAP connection and returns an IMAPEmailChecker instance. This is the recommended way to create an object of this class. The constructor remains available for injecting a pre-existing IMAP connection, ensuring backward compatibility.
    *   **Parameters:**
        *   `$hostname`: The IMAP server hostname (e.g., `imap.example.com`).
        *   `$username`: The username for the IMAP account.
        *   `$password`: The password for the IMAP account.
        *   `$mailbox` (optional `string`, default `INBOX`): The mailbox to connect to.
        *   `$port` (optional `int`, default `993`): The IMAP server port (e.g., 993 for IMAPS/SSL, 143 for IMAP/STARTTLS).
        *   `$flags` (optional `string`, default `/ssl`): Connection flags (e.g., `/ssl`, `/tls`, `/novalidate-cert`).
        *   `$retries` (optional `int`, default `0`): Number of connection retries for `imap_open()`.
        *   `$debug` (optional `bool`, default `false`): Enables verbose logging via `error_log()` for non-critical issues within the created instance.
        *   `$bidRegex` (optional `string`, default `/\\#\\s*(\\d+)/`): A PCRE regex string used to extract an identifier from the email subject. See constructor documentation for details.
    *   **Returns:** `IMAPEmailChecker` - An instance of the class.
    *   **Throws:** `InvalidArgumentException` If `$hostname`, `$username`, or `$bidRegex` is invalid. `RuntimeException` If the IMAP connection fails after all retries.


*   **`__construct($connection, bool $debug = false, string $bidRegex = '/#\s*(\d+)/')`**:
    *   Instantiates the email checker.
    *   **Parameters:**
        *   `$connection`: The established IMAP connection resource or `IMAP\Connection` object.
        *   `$debug` (optional `bool`, default `false`): Enables verbose logging via `error_log()` for non-critical issues.
        *   `$bidRegex` (optional `string`, default `/\\#\\s*(\\d+)/`): A PCRE regex string used to extract an identifier string from the email subject. The regex **must** include a capturing group (typically group 1) that captures the desired ID string and **cannot** be an empty string.
    *   **Throws:** `InvalidArgumentException` If `$bidRegex` is an empty string. `RuntimeException` If the provided `$connection` is invalid or closed.


*   **`checkMailboxStatus()`**:
    *   Checks the status of the current mailbox efficiently.
    *   **Returns:** `array` - An associative array with keys: `total`, `unseen_uids`, `highest_uid`.
    *   **Throws:** `RuntimeException` If any underlying IMAP operation (`check`, `uid`, `search`) fails.


*   **`search(string $criteria, bool $returnUids = true)`**:
    *   Performs a search using custom IMAP criteria.
    *   **Parameters:** `$criteria`, `$returnUids` (optional).
    *   **Returns:** `array` - Sorted array of integer UIDs or sequence numbers. Empty array `[]` if no match.
    *   **Throws:** `InvalidArgumentException` If `$criteria` is empty. `RuntimeException` If the `imap_search` operation fails.


*   **`fetchMessagesByIds(array $identifiers, bool $isUid = true)`**:
    *   Fetches full details for specific messages. **Does not** update class properties `$messages` or `$lastuid`. Handles individual message processing errors internally by logging and skipping.
    *   **Parameters:** `$identifiers`, `$isUid` (optional).
    *   **Returns:** `array` - Associative array of successfully processed message data (see "Message Array Structure"), keyed by identifier. Omits messages that failed processing.


*   **`checkAllEmail()`**:
    *   Retrieves *all* emails. **Use with caution on large mailboxes.** Stores results in `$messages`. Handles individual message processing errors internally by logging and skipping.
    *   **Returns:** `array` - Associative array (keyed by UID) of successfully processed email details. Empty array `[]` if mailbox is empty. Updates `$lastuid`.
    *   **Throws:** `RuntimeException` If the initial `imap_num_msg` fails.


*   **`checkSinceDate(DateTime $date)`**:
    *   Retrieves emails on or after `$date`. Stores results in `$messages`. Handles individual message processing errors internally by logging and skipping.
    *   **Parameters:** `$date`.
    *   **Returns:** `array` - Associative array of successfully processed emails (keyed by UID). Empty array `[]` if no messages found. Updates `$lastuid`.
    *   **Throws:** `RuntimeException` If the initial `imap_search` fails.


*   **`checkSinceLastUID(int $uid)`**:
    *   Retrieves emails with UID > `$uid`. **Recommended for incremental fetching.** Stores results in `$messages`. Handles individual message processing errors internally by logging and skipping.
    *   **Parameters:** `$uid`.
    *   **Returns:** `array` - Associative array of successfully processed emails (keyed by UID). Empty array `[]` if no messages found. Updates `$lastuid`.
    *   **Throws:** `RuntimeException` If the initial `imap_fetch_overview` fails.


*   **`checkUnreadEmails()`**:
    *   Retrieves emails marked `\Unseen`. Stores results in `$messages`. Handles individual message processing errors internally by logging and skipping.
    *   **Returns:** `array` - Associative array of successfully processed unread emails (keyed by UID). Empty array `[]` if no unread emails found. Updates `$lastuid`.
    *   **Throws:** `RuntimeException` If the initial `imap_search` fails.


*   **`setMessageReadStatus(array $uids, bool $markAsRead)`**:
    *   Sets or clears the `\Seen` (read/unread) flag.
    *   **Parameters:** `$uids`, `$markAsRead`.
    *   **Returns:** `void`
    *   **Throws:** `InvalidArgumentException` If `$uids` contains invalid values. `RuntimeException` If the IMAP flag operation fails.


*   **`deleteEmail(int $uid)`**:
    *   Deletes an email by UID (marks and expunges). **Permanent! Use with caution.**
    *   **Parameters:** `$uid`.
    *   **Returns:** `void`
    *   **Throws:** `InvalidArgumentException` If `$uid` is invalid. `RuntimeException` If `imap_delete` or `imap_expunge` fails with an error.


*   **`archiveEmail(int $uid, string $archiveFolder = 'Archive')`**:
    *   Moves an email by UID and expunges. **Use with caution.** Ensure folder exists.
    *   **Parameters:** `$uid`, `$archiveFolder` (optional).
    *   **Returns:** `void`
    *   **Throws:** `InvalidArgumentException` If `$uid` or `$archiveFolder` is invalid. `RuntimeException` If `imap_mail_move` or `imap_expunge` fails with an error.


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
*   **`bid`**: (`string|null`) - A string value extracted from the subject using the regex provided to the constructor (or the default `/#(\d+)/`). The value is taken directly from the **first capturing group** of the regex match. `null` if the regex didn't match or the first capturing group wasn't found.
*   **`unseen`**: (`bool`) - `true` if `\Unseen` flag was set *at the time the message details were fetched*. Check `checkMailboxStatus` for current status.


### Requirements

*   PHP version 8.0 or higher.
*   PHP IMAP extension enabled (`ext-imap`).
*   PHP Multibyte String extension enabled (`ext-mbstring`).


### Notes

*   **Error Handling:** The class uses exceptions for critical errors. Catch `RuntimeException` for IMAP/runtime errors and `InvalidArgumentException` for bad input passed to methods. Use exception messages (`$e->getMessage()`) for details, which include `imap_last_error()` where relevant.
*   **Debug Mode:** You can enable a debug mode by passing `true` as the second argument to the constructor (`new IMAPEmailChecker($conn, true)`). When enabled, non-critical processing issues (e.g., skipping an invalid identifier in a list, failing to decode a specific attachment part, fallback during character encoding) will be logged via `error_log()` for diagnostic purposes. These events are handled gracefully by the class and do not throw exceptions, but logging them can help identify problematic emails or configurations. Exceptions for critical errors are always thrown, regardless of debug mode.
*   **Performance:** `checkMailboxStatus` and `search` are efficient for checking status or finding specific message IDs. `checkSinceLastUID` is best for polling. Fetching full details (`check*`, `fetchMessagesByIds`) is slower. Avoid `checkAllEmail` on large mailboxes.
*   **Security:** **Never hardcode credentials.** Use environment variables or secure configuration methods. Ensure you connect via SSL/TLS.
*   **UID Focus:** The class prioritizes UIDs for reliability.
*   **State Management:** Be aware that only the main `check*` methods update the `$messages` and `$lastuid` properties. `fetchMessagesByIds` returns its results directly without altering the main class state.
*   **UTF-8:** Textual content is normalized to UTF-8.
*   **Resource Management:** The destructor closes the connection. Explicit `imap_close($connection)` after use is still good practice.
*   **Flags:** The `unseen` value in the message array is a snapshot. Use `checkMailboxStatus` or `setMessageReadStatus` for current flag states. `\Recent` flag behavior varies by server.
*   **Custom BID Extraction:** You can provide a custom regular expression to the constructor (`$bidRegex` parameter) to extract an identifier string (called a "bid") from email subjects. Ensure your regex includes a capturing group for the ID string you want to extract. The default extracts numbers following a `#`. This can be used for any sort of identifier that you wish to use such as a Ticket # found in the subject for a ticketing system.