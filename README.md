# IMAPEmailChecker

  

A PHP class to fetch and process emails from an IMAP mailbox. This class provides functionalities to retrieve emails based on different criteria, decode email bodies (including handling inline images), extract attachments, delete and archive emails.

  

## Purpose

  

This class is designed to simplify the process of accessing and managing emails via IMAP in PHP. It can be used for various applications such as:

  

-  **Email Archiving:** Storing emails in a database or other storage for record-keeping.

-  **Automated Email Processing:** Building scripts to analyze incoming emails, trigger actions based on email content, or integrate email data into other systems.

-  **Email Backup Solutions:** Downloading and backing up emails from a mailbox.

  

The class handles complexities like:

  

-  **Decoding MIME Encoded Bodies:** Correctly decodes email bodies in various encodings (e.g., UTF-8, Base64, Quoted-Printable).

-  **Inline Image Embedding:** Automatically embeds inline images within HTML email bodies for easy display.

-  **Attachment Extraction:** Provides access to email attachments with their filenames, content, and types.

-  **UID Management:** Supports fetching new emails since the last processed email using Unique Identifiers (UIDs) for efficient incremental retrieval.

  

## Usage

  

To use the `IMAPEmailChecker` class, you need to have the PHP IMAP extension enabled. You'll first need to establish an IMAP connection using `imap_open()` before instantiating the class.

  

Here's a basic example of how to use the class:

  

```php
<?php

require  'IMAPEmailChecker.php'; // Adjust path if necessary
use IMAPEmailChecker\IMAPEmailChecker;
use  DateTime;

// IMAP connection details - Replace with your actual server details
$hostname = '{your_imap_server:993/imap/ssl}INBOX';
$username = 'your_username';
$password = 'your_password';

// Establish IMAP connection
$connection = imap_open($hostname, $username, $password);

if (!$connection) {
	echo  "Connection failed: "  .  imap_last_error() .  "\n";
	exit();
}

// Instantiate the IMAPEmailChecker class
$emailChecker = new  IMAPEmailChecker($connection);  


// 1. Check all emails in the inbox
echo  "<h3>All Emails:</h3>";
$allEmails = $emailChecker->checkAllEmail();
if (empty($allEmails)) {
	echo  "<p>No emails found.</p>";
} else {
	echo  "<pre>";
	print_r($allEmails);
	echo  "</pre>";
}  
  

// 2. Check emails since a specific date
echo  "<h3>Emails Since Date:</h3>";
$sinceDate = new  DateTime('2024-05-20'); // Example date
$emailsSinceDate = $emailChecker->checkSinceDate($sinceDate);

if ($emailsSinceDate === false) {
	echo  "<p>Error checking emails since date.</p>";
} elseif (empty($emailsSinceDate)) {
	echo  "<p>No emails found since "  .  $sinceDate->format('Y-m-d') .  ".</p>";
} else {
	echo  "<pre>";
	print_r($emailsSinceDate);
	echo  "</pre>";
}


// 3. Check emails since the last UID
echo  "<h3>Emails Since Last UID:</h3>";

// Get the last UID from a previous check (you would have to store it yourself), or use 0 for the first time
$lastUID = $emailChecker->lastuid;
$emailsSinceLastUID = $emailChecker->checkSinceLastUID($lastUID);

if ($emailsSinceLastUID === false) {
	echo  "<p>Error checking emails since last UID.</p>";
} elseif (empty($emailsSinceLastUID)) {
	echo  "<p>No new emails found since last UID: "  .  $lastUID  .  ".</p>";
} else {
	echo  "<pre>";
	print_r($emailsSinceLastUID);
	echo  "</pre>";
	echo  "<p>Last UID updated to: "  .  $emailChecker->lastuid  .  "</p>"; // Store this new last UID for next check
}
```
  
```php
// 4. Delete an email (example with a specific UID, be cautious!)
echo "<h3>Deleting Email (UID 123 - example):</h3>"; // Replace 123 with an actual UID
$deleteResult = $emailChecker->deleteEmail(123);

if ($deleteResult) {
	echo "<p>Email with UID 123 deleted successfully.</p>";
} else {
	echo "<p>Failed to delete email with UID 123.</p>";
	echo "<p>Error: " . imap_last_error() . "</p>";
}
 ``` 

```php
// 5. Archive an email (example with a specific UID, be cautious!)
echo "<h3>Archiving Email (UID 456 - example) to 'Archive' folder:</h3>"; // Replace 456 with an actual UID
$archiveResult = $emailChecker->archiveEmail(456);

if ($archiveResult) {
	echo "<p>Email with UID 456 archived successfully to 'Archive' folder.</p>";
} else {
	echo "<p>Failed to archive email with UID 456.</p>";
	echo "<p>Error: " . imap_last_error() . "</p>";
}
```


  

### Available Methods

  

All  methods  return  data  in  an  array  format, except  for `deleteEmail()` and `archiveEmail()` which  return  boolean  values  indicating  success  or  failure.

  

* **`checkAllEmail()`**:

  

Retrieves  all  emails  from  the  connected  mailbox. Be  cautious  when  using  this  method  on  very  large  inboxes  as it may  consume  significant  resources.

  

* **Returns:** `array` - An  associative  array  where  keys  are  message  numbers (sequence  numbers) and  values  are  arrays  containing  email  details (see "Message  Array  Structure" below). Returns  an  empty  array  if  no  emails  are  found.

  

* **`checkSinceDate(DateTime $date)`**:

  

Searches  for  emails  received  on  or  after  the  specified  date.

  

* **Parameters:**

* `$date` (`DateTime` object): The  date  from  which  to  start  searching  for  emails.

* **Returns:** `array|bool` - An  associative  array  of  emails  received  since  the  given  date (same  structure  as `checkAllEmail()`). Returns `false` on  failure (e.g., if  the `$date` parameter  is  not  provided  or  invalid), or  an  empty  array  if  no  emails  are  found  since  the  date.

  

* **`checkSinceLastUID(int $uid)`**:

  

Retrieves  emails  with  a  UID  greater  than  the  provided  UID. This  is  useful  for  fetching  only  new  emails  since  the  last  check. To  use  this  effectively, you  should  store  the `lastuid` property  after  each  call  to  this  method  and  use  it  for  the  next  call.

  

* **Parameters:**

* `$uid` (`int`): The  last  known  UID. Set  to `0` or `1` for  the  initial  check  if  you  don't  have  a  previous  UID.

* **Returns:** `array|bool` - An  associative  array  of  emails  received  since  the  given  UID (same  structure  as `checkAllEmail()`). Returns `false` on  failure (e.g., if  the `$uid` parameter  is  not  provided  or  invalid), or  an  empty  array  if  no  new  emails  are  found.

  

* **`deleteEmail(int $msgIdentifier)`**:

  

Deletes  an  email  from  the  mailbox. **Use  with  caution  as this action  is  permanent  after  expunging  the  mailbox.**

  

* **Parameters:**

* `$msgIdentifier` (`int`): The  UID  of  the  email  to  delete.

* **Returns:** `bool` - `true` on  successful  deletion  and  expunge, `false` on  failure.

  

* **`archiveEmail(int $msgIdentifier, string $archiveFolder = 'Archive')`**:

  

Archives  an  email  by  moving  it  to  a  specified  folder  and  then  removing  it  from  the  current  mailbox. The  default  archive  folder  is "Archive".

  

* **Parameters:**

* `$msgIdentifier` (`int`): The  UID  of  the  email  to  archive.

* `$archiveFolder` (`string`, optional): The  name  of  the  folder  to  move  the  email  to. Defaults  to 'Archive'.

* **Returns:** `bool` - `true` on  successful  archiving  and  expunge, `false` on  failure.

  

### Public Properties

  

* **`$lastuid`**:

  

* Type: `int`

* Description: After  calling `checkSinceLastUID()`, this  property  will  be  updated  to  the  UID  of  the  last  processed  email. You  should  store  this  value  and  use  it  in  subsequent  calls  to `checkSinceLastUID()` to  efficiently  retrieve  only  new  emails.

  

* **`$messages`**:

  

* Type: `array`

* Description: An  associative  array  containing  the  email  messages  fetched  by  any  of  the `check` methods. The  structure  of  each  message  within  this  array  is  described  below  in "Message  Array  Structure".

  

### Message Array Structure

  

Each  email  message  in  the `$messages` array (returned  by `checkAllEmail()`, `checkSinceDate()`, and `checkSinceLastUID()`) is  an  associative  array  with  the  following  keys:

  

* **`message_id`**: (`string`) - The  unique  Message-ID  header  of  the  email.

* **`subject`**: (`string`) - The  email  subject, decoded  from  MIME  if  necessary.

* **`message_body`**: (`string`) - The  main  body  of  the  email, with  HTML  preferred  over  plain  text  when  available. Inline  images  are  embedded  as data URIs  within  the  HTML  content.

* **`fromaddress`**: (`string`) - The  email  address  of  the  sender.

* **`from`**: (`string`) - The  friendly  name  of  the  sender (if  available), otherwise, it  defaults  to  the `fromaddress`.

* **`message_number`**: (`int`) - The  sequence  number  of  the  message  in  the  mailbox (may  not  be  persistent  across  sessions).

* **`uid`**: (`int`) - The  unique  identifier (UID) of  the  message  in  the  mailbox (persistent).

* **`date`**: (`string`) - The  date  and  time  the  message  was  sent, including  the  UTC  offset (e.g., `Fri, 24 May 2024 15:53:38 -0400`).

* **`to`**: (`array`) - An  array  of  email  addresses  in  the "To" field.

* **`tocount`**: (`int`) - The  number  of "To" addresses.

* **`cc`**: (`array`) - An  array  of  email  addresses  in  the "CC" field.

* **`cccount`**: (`int`) - The  number  of "CC" addresses.

* **`bcc`**: (`array`) - An  array  of  email  addresses  in  the "BCC" field.

* **`bcccount`**: (`int`) - The  number  of "BCC" addresses.

* **`attachments`**: (`array`) - An  array  of  attachments, where  each  attachment  is  an  associative  array  with  the  following  keys:

* **`filename`**: (`string`) - The  filename  of  the  attachment.

* **`content`**: (`string`) - The  raw  content  of  the  attachment.

* **`type`**: (`string`) - The  MIME  subtype  of  the  attachment (e.g., `JPEG`, `PNG`, `PDF`).

* **`content_id`** (optional): (`string`) - Present  for  inline  images, representing  the  Content-ID.

* **`bid`**: (`string`) - (Originally  for  ticket  system  testing) - Extracts  a  number  from  the  subject  if  it  matches  the  pattern `#N` (where N is a number). Returns "n/a" if no match is found.

* **`unseen`**: (`string`) - Indicates  if  the  email  is  unseen (U) or  seen (space  character).

  

### Requirements

  

* PHP  version 7.4 or  higher (due  to  type  declarations  and  namespace  usage).

* PHP  IMAP  extension  enabled. You  may  need  to  install  it  if  it's  not  already  enabled  in  your  PHP  installation (e.g., `sudo  apt-get  install  php-imap` on  Debian/Ubuntu  or  similar  for  your  OS).

  

### Notes

  

* **Error  Handling:** The  class  includes  basic  error  checking, but  you  should  implement  more  robust  error  handling  in  your  application, especially  when  dealing  with  IMAP  connections  and  server  interactions. Check `imap_last_error()` for  more  detailed  error  messages.

* **Performance:** For  very  large  mailboxes, consider  optimizing  your  usage, especially  when  using `checkAllEmail()`. `checkSinceLastUID()` is  generally  more  efficient  for  regularly  fetching  new  emails.

* **Security:** Always  handle  your  IMAP  credentials  securely. Avoid  hardcoding  passwords  directly  in  your  scripts  if  possible. Consider  using  environment  variables  or  secure  configuration  methods.

* **Destructor:** The  class  includes  a  destructor (`__destruct()`) to  automatically  close  the  IMAP  connection  when  the  object  is  no  longer  needed. However, explicitly  closing  the  connection  using `imap_close($connection)` (on  the  connection  resource  used  to  instantiate  the  class) is  still  good  practice  for  resource  management, especially  if  you  are  not  relying  on  the  destructor's  timing.