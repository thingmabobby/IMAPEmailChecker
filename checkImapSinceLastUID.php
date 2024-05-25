<?php
/*
// show emails since specified email UID test
*/

require_once('IMAPEmailChecker.php');

$server = ""; // imap server url
$acct = ""; // email address
$pass = ""; // email password	

use IMAP\Connection;

try {
	$imapConnection = @imap_open("{" . $server . "}", $acct, $pass);
	$checkEmail = new IMAPEmailChecker($imapConnection);
	
	$messages = $checkEmail->checkSinceLastUID(4);

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
		
		if ($checkEmail->lastuid > 0) {
			echo "The last UID was " . $checkEmail->lastuid;
		}
	} else {
		echo "No messages found.";
	}
} catch (Exception $e) {
	echo $e->getMessage();
}
?>