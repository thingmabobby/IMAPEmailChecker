<?php

/*
// This is a class to pull emails from a mailbox using IMAP. You can probably safely ignore the "bid" you'll see. 
// It's something I was testing to be able to organize emails by subject if it found a string of #N (n = a number). Kinda like how ticketing systems work.
// I was testing to see if I could write a script to analyze incoming email messages as a cron job and save them to a database and associate them with specific items.

// Usage:
//
// Instantiating the class requires connection information: $checkemail = new CheckImapEmail($server,$acct,$pass);
//
// There are 3 methods available and they all return data in an array unless the error property is set to true (then it returns an error string):
//
// 	checkEmail() 
//		- this in theory should return all of your emails, but I haven't fully tested it in a large inbox.
//  
//	checkSinceDate($thedate) 
//		- this will search for emails since the given date from the mailbox. $thedate needs to be in a string of date format: "d M Y" (e.g. 24 May 2024)
//
//	checkSinceLastUID($uid)
//		- this will search for emails since the last specified email number (UID) - ideally you would save whatever the last UID was so you can lookup the new emails the next time using that value
//
*/
class CheckImapEmail {
	private $conn;
	
	public $msg_count = 0; // how many total emails are found
	public $lastuid = 0; // if searching by last UID it will return the last UID found so you can store it and refer to it in your next search to pull the latest emails since the last time you searched
	public $messages = array();
	public $error = false;
	
	
	public function __construct($server,$acct,$pass,$port = "") {
		if (!$server || !$acct || !$pass) { return false; }
		
		if (!empty($port)) { $server .= ":" . $port; }
		
		$this->conn = @imap_open("{" . $server . "}", $acct, $pass);
	}
	
	
	public function __destruct() {
		if (isset($this->conn)) { unset($this->conn); }
	}
	
	
	private function showError($error) {
		if ($error && is_array($error)) {
			$errorstring = "";
			foreach ($error as $thiserror) {
				$errorstring .= $thiserror . "\n";
			}
			$this->error = true;
			return $errorstring;
		}
	}
	
	
	/*
	// this method will check the mailbox and return each email it finds as an array
	*/
	public function checkEmail() {
		if (!$this->conn) { return $this->showError(imap_errors()); }
		
		$this->msg_count = imap_num_msg($this->conn);
	
		for($i = 1; $i <= $this->msg_count; $i++) {
			$header = imap_headerinfo($this->conn, $i);
		
			$this->messages[$i]['subject'] = $header->Subject;
			$this->messages[$i]['message_body'] = imap_fetchbody($this->conn, $i, 2);
			$this->messages[$i]['fromaddress'] = $header->sender[0]->mailbox . "@" . $header->sender[0]->host;
			$this->messages[$i]['from'] = $header->fromaddress;
			$this->messages[$i]['message_number'] = $header->Msgno;
			$this->messages[$i]['date'] = $header->date;	
			
			$thisbid = "n/a";
			if (property_exists($header,"Subject") && preg_match("/#(\d+)/", $header->Subject, $matches)) {
				$thisbid = $matches[0];
			}
			$this->messages[$i]['bid'] = $thisbid;
			
			$this->messages[$i]['unseen'] = $header->Unseen;			
		}
		
		return $this->messages;
	}
	
	
	/*
	// this method will search for emails since the given date from the mailbox and return each email it finds as an array
	// $thedate needs to be in a string of date format: "d M Y" (e.g. 24 May 2024)
	*/
	public function checkSinceDate($thedate) {
		if (!$this->conn) { return $this->showError(imap_errors()); }
		if (!isset($thedate)) { return false; }
		
		$search = imap_search($this->conn, "SINCE \"" . $thedate . "\"", SE_UID); 
		
		if (!$search) { return false; }
		if (!is_array($search)) { return false; }
		if (count($search) == 0) { return false; }
		
		$this->msg_count = count($search);
		
		foreach ($search as $thismsg) {
			$header = imap_headerinfo($this->conn, $thismsg);
		
			$this->messages[$thismsg]['subject'] = $header->Subject;
			$this->messages[$thismsg]['message_body'] = imap_fetchbody($this->conn, $thismsg, 2);
			$this->messages[$thismsg]['fromaddress'] = $header->sender[0]->mailbox . "@" . $header->sender[0]->host;
			$this->messages[$thismsg]['from'] = $header->fromaddress;
			$this->messages[$thismsg]['message_number'] = $header->Msgno;
			$this->messages[$thismsg]['date'] = $header->date;	
			
			$thisbid = "n/a";
			if (property_exists($header,"Subject") && preg_match("/#(\d+)/", $header->Subject, $matches)) {
				$thisbid = $matches[0];
			}				
			$this->messages[$thismsg]['bid'] = $thisbid;
			
			$this->messages[$thismsg]['unseen'] = $header->Unseen;
		}
		
		return $this->messages;
	}
	
	
	/*
	// this method checks all emails since the last specified email number (UID) - ideally you would save whatever the last UID was so you can lookup the new emails the next time using that value
	*/
	public function checkSinceLastUID($uid) {
		if (!$this->conn) { return $this->showError(imap_errors()); }
		if (!isset($uid)) { return false; }
		
		// grab the overview details from the mailbox starting from the specified message ID (UID)
		$search = imap_fetch_overview($this->conn, $uid . ":*", FT_UID); 
		
		if (!$search) { return false; }
		if (!is_array($search)) { return false; }
		if (count($search) == 0) { return false; }
			
		$this->msg_count = count($search);
		
		foreach ($search as $thisuid) {
			$thismsg = $thisuid->uid;
						
			$header = imap_headerinfo($this->conn, $thismsg);
			
			$this->messages[$thismsg]['subject'] = $header->Subject;
			$this->messages[$thismsg]['message_body'] = imap_fetchbody($this->conn, $thismsg, 2);
			$this->messages[$thismsg]['fromaddress'] = $header->sender[0]->mailbox . "@" . $header->sender[0]->host;
			$this->messages[$thismsg]['from'] = $header->fromaddress;
			$this->messages[$thismsg]['message_number'] = $header->Msgno;
			$this->messages[$thismsg]['date'] = $header->date;

			$thisbid = "n/a";
			if (property_exists($header,"Subject") && preg_match("/#(\d+)/", $header->Subject, $matches)) {
				$thisbid = $matches[0];
			}			
			$this->messages[$thismsg]['bid'] = $thisbid;			
			
			$this->messages[$thismsg]['unseen'] = $header->Unseen;
		}		
		$this->lastuid = $thismsg;		
		
		return $this->messages;
	}
}


$server = ""; // imap server url (usually imap.domain.tld)
$acct = ""; // email address
$pass = ""; // email password
$checkemail = new CheckImapEmail($server,$acct,$pass);


/* show all emails test */
//$messages = $checkemail->checkEmail();


/* search by date test */
//$thedate = "24 May 2024";
//$messages = $checkemail->checkSinceDate($thedate);


/* show emails since specified email UID test */
$messages = $checkemail->checkSinceLastUID(4);


// if an error was returned then show the error, otherwise loop through the messages
if ($checkemail->error) {
	echo "Error(s): " . $messages;
}
else {
	if ($messages) {
		echo "<h2>Emails found: " . $checkemail->msg_count . "</h2>";
		
		// loop through the emails
		foreach ($messages as $message) {
			echo "Message #" . $message['message_number'] . "<br>";
			echo "Bid: " . $message['bid'] . "<br>";
			echo "Date: " . $message['date'] . "<br>";
			echo "From: " . $message['from'] . " (" . $message['fromaddress'] . ")<br>";
			echo "Unread? " . $message['unseen'] . "<br>";
			echo "Subject: " . $message['subject'] . "<br>";
			echo "Body:<br>" . $message['message_body'] . "<br><br>";		
		}
		
		if ($checkemail->lastuid > 0) {
			echo "The last UID was " . $checkemail->lastuid;
		}
	}
	else {
		echo "No messages found.";
	}
}
