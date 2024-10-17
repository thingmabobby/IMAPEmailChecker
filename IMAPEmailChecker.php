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
//			-> to - an array of email addresses in the "to" line
//			-> tocount - count of how many "to" addresses there are
//			-> cc - an array of email addresses in the "cc" line
//			-> cccount - count of how many "cc" addresses there are
//			-> bcc - an array of email addresses in the "bcc" line
//			-> bcccount - count of how many "bcc" addresses there are
//			-> attachments - associative array with the following fields: filename, content, type
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

		$messageParts = [];
		$hasHtml = false; // Track if HTML has been added

		// Function to recursively decode parts
		$decodePart = function($part, $partNum) use (&$decodePart, $thismsg, &$messageParts, &$hasHtml) {
			// Check if the part is an attachment
			if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
				return; // Skip attachments
			}

			// Construct the section string for imap_fetchbody
			$partIndex = (string)$partNum; // Ensure partNum is a string
			$body = imap_fetchbody($this->conn, $thismsg, $partIndex);

			// Handle encoding for this part
			switch ($part->encoding) {
				case 0:  // 7bit
					break;  // No decoding necessary
				case 1:  // 8bit
					$body = imap_8bit($body);
					break;
				case 2:  // Binary
					break;  // Binary data, no decoding necessary
				case 3:  // Base64
					$body = imap_base64($body);
					break;
				case 4:  // Quoted-Printable
					$body = quoted_printable_decode($body);
					break;
				default:
					break;  // Unknown encoding
			}

			// Check if the part is multipart
			if (isset($part->parts) && is_array($part->parts)) {
				foreach ($part->parts as $subIndex => $subPart) {
					$decodePart($subPart, $partNum . '.' . ($subIndex + 1)); // Recursive call with updated part number
				}
			} else {
				// Prefer HTML over plain text
				if (stripos($part->subtype, 'html') !== false) {
					// Always add HTML if it is found
					$messageParts = [$body]; // Overwrite any existing parts with HTML
					$hasHtml = true; // Mark that we have added HTML
				} elseif (stripos($part->subtype, 'plain') !== false) {
					if (!$hasHtml) { // Only add plain text if no HTML has been added yet
						$messageParts[] = $body; 
					} 
				}
			}
		};

		// Start decoding the main parts
		if (isset($structure->parts)) {
			foreach ($structure->parts as $index => $part) {
				$decodePart($part, $index + 1); // Start with a 1-based index
			}
		} else {
			// Handle the case for single part messages
			$decodePart($structure, 1); // Treat the entire structure as a single part
		}

		// Join all parts for multipart messages, separating with new lines
		$message = implode("\n", $messageParts);

		// Return trimmed message to avoid leading/trailing whitespace
		return trim($message) ?: false;
	}
	
	
	private function checkForAttachments(int $thismsg): array
	{
		$structure = imap_fetchstructure($this->conn, $thismsg);
		$attachments = [];

		if (isset($structure->parts) && is_array($structure->parts)) {
			for ($i = 0; $i < count($structure->parts); $i++) {
				$part = $structure->parts[$i];
				
				// Check if the part is an attachment
				if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
					// Get attachment details
					$filename = isset($part->dparameters[0]->value) ? $part->dparameters[0]->value : 'unknown';
					$content = imap_fetchbody($this->conn, $thismsg, (string)($i + 1)); // parts are 1-indexed
					
					// Decode content based on encoding
					switch ($part->encoding) {
						case 3:  // Base64
							$content = imap_base64($content);
							break;
						case 4:  // Quoted-printable
							$content = quoted_printable_decode($content);
							break;
					}

					// Add attachment to the list
					$attachments[] = [
						'filename' => $filename,
						'content' => $content,
						'type' => $part->subtype,
					];
				}
			}
		}

		return $attachments;
	}
	
	
	private function getRecipientAddresses(string $type, int $thismsg, \stdClass $rfc_header): array|bool
	{
		
		if ($type != "to" && $type != "cc" && $type != "bcc") { 
			return false; 
		}
		if (!$thismsg || empty($thismsg) || !$rfc_header || empty($rfc_header)) { 
			return false; 
		}
		
		$toaddresses = [];
		
		if ($type == "to") {
			foreach ($rfc_header->to as $thisto) {
				$toaddresses[] = $thisto->mailbox . "@" . $thisto->host;
			}
		}
		if ($type == "cc") {
			foreach ($rfc_header->cc as $thisto) {
				$toaddresses[] = $thisto->mailbox . "@" . $thisto->host;
			}
		}
		if ($type == "bcc") {
			foreach ($rfc_header->bcc as $thisto) {
				$toaddresses[] = $thisto->mailbox . "@" . $thisto->host;
			}
		}
		
		return $toaddresses;		
	}
	
	
	/*
	// this method will check the mailbox and return each email it finds as an array
	*/
	public function checkAllEmail(): array 
	{	
		$msg_count = imap_num_msg($this->conn);
		
		imap_headers($this->conn);
	
		for ($i = 1; $i <= $msg_count; $i++) {
			$header = imap_headerinfo($this->conn, $i);
			
			// had to do this because the original $header would only show me the 1st to address/cc address/bcc address instead of returning all of them like the docs said
			$rfc_header = imap_rfc822_parse_headers(imap_fetchheader($this->conn, $i));
			
			$message = $this->decodeBody($i);
			if (!$message) {
				continue;
			}
			
			$this->messages[$i]['message_id'] = htmlentities($header->message_id);
			$this->messages[$i]['subject'] = $header->Subject;
			$this->messages[$i]['message_body'] = $message;
			
			if (isset($rfc_header->to)) {
				$this->messages[$i]['tocount'] = count($rfc_header->to);
				$this->messages[$i]['to'] = $this->getRecipientAddresses("to",$i,$rfc_header);
			}
			
			if (isset($rfc_header->cc) && !empty($rfc_header->cc)) {
				$this->messages[$i]['cccount'] = count($rfc_header->cc);
				$this->messages[$i]['cc'] = $this->getRecipientAddresses("cc",$i,$rfc_header);
			}

			if (isset($rfc_header->bcc) && !empty($rfc_header->bcc)) {
				$this->messages[$i]['bcccount'] = count($rfc_header->bcc);
				$this->messages[$i]['bcc'] = $this->getRecipientAddresses("bcc",$i,$rfc_header);
			}
			
			$this->messages[$i]['cc'] = $header->cc;
			$this->messages[$i]['bcc'] = $header->bcc;
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

			$attachments = $this->checkForAttachments($i);
			$this->messages[$i]['attachments'] = $attachments;			
		}
		
		return $this->messages;
	}
	
	
	/*
	// this method will search for emails since the given date from the mailbox and return each email it finds as an array
	// $thedate needs to be in a string of date format: "d M Y" (e.g. 24 May 2024)
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
		
		imap_headers($this->conn);
		
		foreach ($search as $thismsg) {
			$header = imap_headerinfo($this->conn, $thismsg);
			
			// had to do this because the original $header would only show me the 1st to address/cc address/bcc address instead of returning all of them like the docs said
			$rfc_header = imap_rfc822_parse_headers(imap_fetchheader($this->conn, $thismsg));
			
			$message = $this->decodeBody($thismsg);
			if (!$message) {
				continue;
			}
			
			$this->messages[$thismsg]['message_id'] = htmlentities($header->message_id);
			$this->messages[$thismsg]['subject'] = $header->Subject;
			$this->messages[$thismsg]['message_body'] = $message;
			
			if (isset($rfc_header->to)) {
				$this->messages[$thismsg]['tocount'] = count($rfc_header->to);
				$this->messages[$thismsg]['to'] = $this->getRecipientAddresses("to",$thismsg,$rfc_header);
			}
			
			if (isset($rfc_header->cc) && !empty($rfc_header->cc)) {
				$this->messages[$thismsg]['cccount'] = count($rfc_header->cc);
				$this->messages[$thismsg]['cc'] = $this->getRecipientAddresses("cc",$thismsg,$rfc_header);
			}

			if (isset($rfc_header->bcc) && !empty($rfc_header->bcc)) {
				$this->messages[$thismsg]['bcccount'] = count($rfc_header->bcc);
				$this->messages[$thismsg]['bcc'] = $this->getRecipientAddresses("bcc",$thismsg,$rfc_header);
			}
			
			$this->messages[$thismsg]['cc'] = $header->cc;
			$this->messages[$thismsg]['bcc'] = $header->bcc;
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
			
			$attachments = $this->checkForAttachments($thismsg);
			$this->messages[$thismsg]['attachments'] = $attachments;
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
		
		imap_headers($this->conn);
		
		foreach ($search as $thisuid) {
			$thismsg = $thisuid->uid;
			$header = imap_headerinfo($this->conn, $thismsg);
			
			// had to do this because the original $header would only show me the 1st to address/cc address/bcc address instead of returning all of them like the docs said
			$rfc_header = imap_rfc822_parse_headers(imap_fetchheader($this->conn, $thismsg));			
			
			$message = $this->decodeBody($thismsg);
			if (!$message) {
				continue;
			}
			
			$this->messages[$thismsg]['message_id'] = htmlentities($header->message_id);
			$this->messages[$thismsg]['subject'] = $header->Subject;
			$this->messages[$thismsg]['message_body'] = $message;
			
			if (isset($rfc_header->to)) {
				$this->messages[$thismsg]['tocount'] = count($rfc_header->to);
				$this->messages[$thismsg]['to'] = $this->getRecipientAddresses("to",$thismsg,$rfc_header);
			}
			
			if (isset($rfc_header->cc) && !empty($rfc_header->cc)) {
				$this->messages[$thismsg]['cccount'] = count($rfc_header->cc);
				$this->messages[$thismsg]['cc'] = $this->getRecipientAddresses("cc",$thismsg,$rfc_header);
			}

			if (isset($rfc_header->bcc) && !empty($rfc_header->bcc)) {
				$this->messages[$thismsg]['bcccount'] = count($rfc_header->bcc);
				$this->messages[$thismsg]['bcc'] = $this->getRecipientAddresses("bcc",$thismsg,$rfc_header);
			}

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
			
			$attachments = $this->checkForAttachments($thismsg);
			$this->messages[$thismsg]['attachments'] = $attachments;
		}		
		$this->lastuid = $thismsg;		
		
		return $this->messages;
	}
}
