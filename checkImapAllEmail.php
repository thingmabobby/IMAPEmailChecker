<?php
/* 
show all emails example 

..\email.php is an example configuration file that should include the variables assigned with your IMAP server, email account, and email password

$IMAPEmailCheckerServer = "imap.yourhost.com";
$IMAPEmailCheckerAccount = "email@domain.com";
$IMAPEmailCheckerPassword = "yourpassword";

*/

require_once('IMAPEmailChecker.php');
require_once('..\email.php');
use IMAPEmailChecker\IMAPEmailChecker;

$imapConnection = @imap_open("{" . $IMAPEmailCheckerServer . "}", $IMAPEmailCheckerAccount, $IMAPEmailCheckerPassword);
if (!$imapConnection) {
	echo "Error connecting to IMAP mailbox!";
} else {
	$checkEmail = new IMAPEmailChecker($imapConnection);

	$messages = $checkEmail->checkAllEmail();

	if ($messages) {
		echo "<h2>Emails found: " . count($messages) . "</h2>";
		
		// loop through the emails
		foreach ($messages as $message) {
			echo "
			Message #" . $message['message_number'] . "<br>
			Bid: " . $message['bid'] . "<br>
			Date: " . $message['date'] . "<br>
			From: " . $message['from'] . " (" . $message['fromaddress'] . ")<br>
			Unread? " . $message['unseen'] . "<br>
			Subject: " . $message['subject'] . "<br>
			Body:<br>" . $message['message_body'] . "<br><br>";		
		}
	} else {
		echo "No messages found.";
	}
}

?>