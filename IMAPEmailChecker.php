<?php

/*
// This is a class to pull emails from a mailbox using IMAP. You can probably safely ignore the "bid" you'll see. 
// It's something I was testing to be able to organize emails by subject if it found a string of #N (n = a number). Kinda like how ticketing systems work.
// I was testing to see if I could write a script to analyze incoming email messages as a cron job and save them to a database and associate them with specific items.
//
// Usage:
//
//
// There are 3 methods available and they all return data in an array:
//
// 	checkAllEmail() 
//		- this in theory should return all of your emails, but I haven't fully tested it in a large inbox.
//  
//	checkSinceDate($thedate) 
//		- this will search for emails since the given date from the mailbox. $thedate needs to be a DateTime object.
//
//	checkSinceLastUID($uid)
//		- this will search for emails since the last specified email number (UID) - ideally you would save whatever the last UID was so you can lookup the new emails the next time using that value.
//
//
//	Public Properties:
//		lastuid - if searching by last UID it will return the last UID found so you can store it and refer to it in your next search to pull the latest emails since the last time you searched
//		messages - associative array containing the email messages found with the following fields:
//			-> subject - the email subject
//			-> message_body - the body of the email
//			-> fromaddress - the email address that sent the email
//			-> from - the friendly name (e.g. Jon Doe)
//			-> message_number - the unique ID of the message (UID) from the mailbox
//			-> date - the date and time with UTC difference the message was sent (e.g. Fri, 24 May 2024 15:53:38 -0400)
*/

declare(strict_types=1);
namespace IMAPEmailChecker;
use \DateTime;

class IMAPEmailChecker
{	
	private $conn;
		
	public function __construct($connection, public int $lastuid = 0, public array $messages = []) 
	{	
		$this->conn = $connection;
	}
	
	
	public function __destruct() 
	{
		if (isset($this->conn)) { 
			imap_close($this->conn); 
		}
	}
	
	
	private function validateResults(array|bool $results): bool
	{
		if (!$results) {
			return false;
		}

		if (!is_array($results)) {
			return false;
		}

		if (count($results) == 0) {
			return false;
		}
		
		return true;
	}
	
	private function decodeBody(int $thismsg): string|bool
	{
		$structure = imap_fetchstructure($this->conn, $thismsg);
		if (!$structure) { 
			return false;
		}
		
		$message = imap_fetchbody($this->conn,$thismsg,"2");
		if (!$message) {
			return false;
		}
		
		if (isset($structure->parts) && is_array($structure->parts) && isset($structure->parts[1])) {
			$part = $structure->parts[1];

			if ($part->encoding == 3) {
				$message = imap_base64($message);
			} else if ($part->encoding == 1) {
				$message = imap_8bit($message);
			} else {
				$message = quoted_printable_decode($message);
			}
		}
		else {
			return false;
		}
		
		return $message;
	}
	
	
	/*
	// this method will check the mailbox and return each email it finds as an array
	*/
	public function checkAllEmail(): array 
	{	
		$msg_count = imap_num_msg($this->conn);
	
		for ($i = 1; $i <= $msg_count; $i++) {
			$header = imap_headerinfo($this->conn, $i);
			
			$message = $this->decodeBody($i);
			if (!$message) {
				continue;
			}
			
			$this->messages[$i]['message_id'] = htmlentities($header->message_id);
			$this->messages[$i]['subject'] = $header->Subject;
			$this->messages[$i]['message_body'] = $message;
			$this->messages[$i]['fromaddress'] = $header->sender[0]->mailbox . "@" . $header->sender[0]->host;
			$this->messages[$i]['from'] = $header->fromaddress;
			$this->messages[$i]['message_number'] = $header->Msgno;
			$this->messages[$i]['date'] = $header->date;	
			
			$thisbid = "n/a";
			if (property_exists($header,"Subject") && preg_match("/#(\d+)/", $header->Subject, $matches)) {
				$thisbid = $matches[0];
			}
			$this->messages[$i]['bid'] = str_replace("#","",$thisbid);
			
			$this->messages[$i]['unseen'] = $header->Unseen;			
		}
		
		return $this->messages;
	}
	
	
	/*
	// this method will search for emails since the given date from the mailbox and return each email it finds as an array
	// $thedate needs to be a DateTime object
	*/
	public function checkSinceDate(DateTime $date): bool | array 
	{
		if (!isset($date)) { 
			return false; 
		}
		
		$thedate = $date->format('d M Y');
		$search = imap_search($this->conn, "SINCE \"" . $thedate . "\"", SE_UID); 
		
		if (!$this->validateResults($search)) {
			return false;
		}
				
		foreach ($search as $thismsg) {
			$header = imap_headerinfo($this->conn, $thismsg);
		
			$message = $this->decodeBody($thismsg);
			if (!$message) {
				continue;
			}
			
			$this->messages[$thismsg]['message_id'] = htmlentities($header->message_id);
			$this->messages[$thismsg]['subject'] = $header->Subject;
			$this->messages[$thismsg]['message_body'] = $message;
			$this->messages[$thismsg]['fromaddress'] = $header->sender[0]->mailbox . "@" . $header->sender[0]->host;
			$this->messages[$thismsg]['from'] = $header->fromaddress;
			$this->messages[$thismsg]['message_number'] = $header->Msgno;
			$this->messages[$thismsg]['date'] = $header->date;	
			
			$thisbid = "n/a";
			if (property_exists($header,"Subject") && preg_match("/#(\d+)/", $header->Subject, $matches)) {
				$thisbid = $matches[0];
			}				
			$this->messages[$thismsg]['bid'] = str_replace("#","",$thisbid);
			
			$this->messages[$thismsg]['unseen'] = $header->Unseen;
		}
		
		return $this->messages;
	}
	
	
	/*
	// this method checks all emails since the last specified email number (UID) - ideally you would save whatever the last UID was so you can lookup the new emails the next time using that value
	*/
	public function checkSinceLastUID(int $uid): bool | array 
	{
		if (!isset($uid)) { 
			return false; 
		}
		
		// grab the overview details from the mailbox starting from the specified message ID (UID)
		$search = imap_fetch_overview($this->conn, $uid . ":*", FT_UID); 
		
		if (!$this->validateResults($search)) {
			return false;
		}
		
		foreach ($search as $thisuid) {
			$thismsg = $thisuid->uid;
			$header = imap_headerinfo($this->conn, $thismsg);
			
			$message = $this->decodeBody($thismsg);
			if (!$message) {
				continue;
			}
			
			$this->messages[$thismsg]['message_id'] = htmlentities($header->message_id);
			$this->messages[$thismsg]['subject'] = $header->Subject;
			$this->messages[$thismsg]['message_body'] = $message;
			$this->messages[$thismsg]['fromaddress'] = $header->sender[0]->mailbox . "@" . $header->sender[0]->host;
			$this->messages[$thismsg]['from'] = $header->fromaddress;
			$this->messages[$thismsg]['message_number'] = $header->Msgno;
			$this->messages[$thismsg]['date'] = $header->date;

			$thisbid = "n/a";
			if (property_exists($header,"Subject") && preg_match("/#(\d+)/", $header->Subject, $matches)) {
				$thisbid = $matches[0];
			}			
			$this->messages[$thismsg]['bid'] = str_replace("#","",$thisbid);
			
			$this->messages[$thismsg]['unseen'] = $header->Unseen;
		}		
		$this->lastuid = $thismsg;		
		
		return $this->messages;
	}
}
