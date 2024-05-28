<?php
/* 
// show all emails test 
*/

require_once('IMAPEmailChecker.php');

$server = ""; // imap server url
$acct = ""; // email address
$pass = ""; // email password	

$imapConnection = @imap_open("{" . $server . "}", $acct, $pass);
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