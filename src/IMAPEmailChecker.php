<?php
declare(strict_types=1);

namespace IMAPEmailChecker;

use DateTime;
use stdClass;

// Define IMAP constants if they aren't already (useful for environments where they might not be auto-loaded)
if (!defined('TYPETEXT')) define('TYPETEXT', 0);
if (!defined('TYPEMULTIPART')) define('TYPEMULTIPART', 1);
if (!defined('TYPEMESSAGE')) define('TYPEMESSAGE', 2);
if (!defined('TYPEAPPLICATION')) define('TYPEAPPLICATION', 3);
if (!defined('TYPEAUDIO')) define('TYPEAUDIO', 4);
if (!defined('TYPEIMAGE')) define('TYPEIMAGE', 5);
if (!defined('TYPEVIDEO')) define('TYPEVIDEO', 6);
if (!defined('TYPEMODEL')) define('TYPEMODEL', 7);
if (!defined('TYPEOTHER')) define('TYPEOTHER', 8);

if (!defined('ENC7BIT')) define('ENC7BIT', 0);
if (!defined('ENC8BIT')) define('ENC8BIT', 1);
if (!defined('ENCBINARY')) define('ENCBINARY', 2);
if (!defined('ENCBASE64')) define('ENCBASE64', 3);
if (!defined('ENCQUOTEDPRINTABLE')) define('ENCQUOTEDPRINTABLE', 4);
if (!defined('ENCOTHER')) define('ENCOTHER', 5);

/**
 * A PHP class to fetch and process emails from an IMAP mailbox.
 *
 * This class provides an API to search and retrieve email messages by various criteria 
 * (e.g., all messages, since a certain date, after a specific UID) and to handle common 
 * email processing tasks. It can decode message bodies (handling different content encodings 
 * and inline images), extract attachments, and manage message flags (marking emails as 
 * read or unread). Additionally, it supports performing custom IMAP searches with flexible 
 * criteria and includes methods to delete emails or move them to an archive folder. The class 
 * utilizes IMAP unique identifiers (UIDs) for reliable message identification and processing.
 *
 * Key methods:
 * - **checkAllEmail()** – Fetches all emails from the mailbox.
 * - **checkSinceDate(DateTime $date)** – Fetches emails received since the specified date.
 * - **checkSinceLastUID(int $uid)** – Fetches emails with UIDs greater than the given UID (i.e., newer messages).
 * - **deleteEmail(int $uid)** – Deletes the specified email from the mailbox.
 * - **archiveEmail(int $uid, string $folder)** – Moves the specified email to the given folder (e.g., an archive).
 *
 * @package IMAPEmailChecker
 * @author Thingmabobby <thingmabobby@gmail.com>
 * @license http://unlicense.org/ Unlicense
 * @link    https://github.com/thingmabobby/IMAPEmailChecker GitHub Repository
 */
class IMAPEmailChecker
{
	/**
	 * IMAPEmailChecker constructor.
	 *
	 * @param resource $conn      The IMAP connection resource.
	 * @param int      $lastuid   The UID of the last processed email.
	 * @param array    $messages  The list of messages fetched.
	 */
	public function __construct(
		private $conn,
		public int $lastuid = 0,
		public array $messages = []
	) {}


	/**
	 * IMAPEmailChecker destructor.
	 * Closes the IMAP connection.
	 */
	public function __destruct()
	{
		if (is_resource($this->conn)) {
			imap_close($this->conn);
		}
	}


	/**
	 * Validates the result of an IMAP search or overview operation.
	 *
	 * @param array|bool $results The results to validate.
	 * @return bool True if the results are valid (non-empty array); false otherwise.
	 */
	private function validateResults(array|bool $results): bool
	{
		// Check if it's false (error) or not an array or an empty array
		if ($results === false || !is_array($results) || count($results) === 0) {
			return false;
		}
		return true;
	}


	/**
	 * Checks the status of the current mailbox, including UIDs for recent/unseen and the highest UID.
	 *
	 * Retrieves:
	 * - Total number of messages.
	 * - The highest UID currently existing in the mailbox.
	 * - An array containing the UIDs of recent messages (\Recent flag).
	 * - An array containing the UIDs of unseen messages (\Unseen flag).
	 *
	 * Note: 'Recent' messages have the \Recent flag. 'Unseen' messages have the \Unseen flag.
	 * The highest UID is determined by finding the UID of the message with the highest sequence number.
	 *
	 * @return array|bool An associative array with keys 'total' (int), 'highest_uid' (int),
	 *                    'recent_uids' (array<int>), and 'unseen_uids' (array<int>),
	 *                    or false on failure of any required IMAP operation.
	 *                    'highest_uid' will be 0 if the mailbox is empty.
	 *                    Example: ['total' => 150, 'highest_uid' => 2345, 'recent_uids' => [2343, 2344, 2345], 'unseen_uids' => [2340, 2343, 2345]]
	 */
	public function checkMailboxStatus(): array|bool
	{
		$status = [
			'total' => 0,
			'highest_uid' => 0, // Initialize to 0 for empty mailbox case
			'recent_uids' => [],
			'unseen_uids' => [],
		];

		// 1. Get total count using imap_check()
		$check = imap_check($this->conn);
		if ($check === false || !($check instanceof \stdClass)) {
			error_log("IMAPEmailChecker: Failed to get mailbox check status (imap_check). Error: " . imap_last_error());
			return false;
		}
		$status['total'] = isset($check->Nmsgs) ? (int)$check->Nmsgs : 0;

		// 2. Get highest UID using imap_uid() with the highest sequence number, if mailbox is not empty
		if ($status['total'] > 0) {
			// imap_uid() takes the sequence number and returns the corresponding UID
			$highestUidResult = imap_uid($this->conn, $status['total']);
			if ($highestUidResult === false) {
				// This could happen if the message count changed between imap_check and imap_uid,
				// or if the server has issues. Treat as failure.
				error_log("IMAPEmailChecker: Failed to get UID for highest sequence number ({$status['total']}). Error: " . imap_last_error());
				return false; // Fail if we can't get this reliably
			}
			$status['highest_uid'] = (int)$highestUidResult;
		}
		// If total is 0, highest_uid remains 0.

		// 3. Get unseen UIDs using imap_search()
		$unseenUidsResult = imap_search($this->conn, 'UNSEEN', SE_UID);
		if ($unseenUidsResult === false) {
			error_log("IMAPEmailChecker: Failed to search for unseen message UIDs (imap_search UNSEEN). Error: " . imap_last_error());
			return false;
		}
		$status['unseen_uids'] = array_map('intval', $unseenUidsResult);

		// 4. Get recent UIDs using imap_search()
		$recentUidsResult = imap_search($this->conn, 'RECENT', SE_UID);
		if ($recentUidsResult === false) {
			error_log("IMAPEmailChecker: Failed to search for recent message UIDs (imap_search RECENT). Error: " . imap_last_error());
			return false;
		}
		$status['recent_uids'] = array_map('intval', $recentUidsResult);

		// 5. Return the combined status
		return $status;
	}


	/**
     * Performs a search on the current mailbox using custom IMAP criteria.
     *
     * Allows searching based on various criteria supported by the IMAP server.
     * Refer to RFC 3501 (Section 6.4.4 SEARCH Command) for standard criteria.
     * Common examples: 'ALL', 'UNSEEN', 'SEEN', 'ANSWERED', 'DELETED', 'FLAGGED',
     * 'FROM "user@example.com"', 'SUBJECT "Invoice"', 'BODY "important text"',
     * 'SINCE "1-Jan-2024"', 'BEFORE "31-Dec-2023"', 'KEYWORD "MyFlag"'.
     *
     * @param string $criteria   The IMAP search criteria string. Must not be empty.
     * @param bool   $returnUids If true (default), returns an array of UIDs.
     *                           If false, returns an array of message sequence numbers.
     * @return array|false An array of integer UIDs or message numbers matching the criteria,
     *                     sorted numerically. Returns an empty array if no messages match.
     *                     Returns false if the search operation fails or criteria is empty.
     */
    public function search(string $criteria, bool $returnUids = true): array|false
    {
        // Basic validation for the criteria string
        $trimmedCriteria = trim($criteria);
        if ($trimmedCriteria === '') {
            error_log("IMAPEmailChecker: Search criteria cannot be empty.");
            return false;
        }

        // Determine the options for imap_search
        $options = $returnUids ? SE_UID : 0; // SE_UID to return UIDs, 0 for sequence numbers

        // Perform the search
        $results = imap_search($this->conn, $trimmedCriteria, $options);

        if ($results === false) {
            // Search failed
            error_log("IMAPEmailChecker: imap_search failed for criteria '{$trimmedCriteria}'. Error: " . imap_last_error());
            return false;
        }

        // imap_search returns an array of integers (or false on error).
        // If no messages match, it returns an empty array (which is not === false).
        // Sort the results numerically for consistency.
        sort($results, SORT_NUMERIC);

        // Return the sorted array of identifiers (UIDs or sequence numbers)
        return $results;
    }


	/**
	 * Decodes the body of an email message, identified by message number or UID.
	 *
	 * Recursively processes MIME parts, preferring HTML over plain text.
	 * Converts content to UTF-8 by:
	 *   1) reading any declared charset param,
	 *   2) or auto‑detecting via mb_detect_encoding().
	 *
	 * @param int $identifier The message sequence number or UID.
	 * @param bool $isUid Whether the identifier is a UID (true) or message number (false).
	 * @return string|false The decoded UTF-8 body, or false on failure.
	 */
	private function decodeBody(int $identifier, bool $isUid = false): string|false
	{
		$options = $isUid ? FT_UID : 0;
		$structure = imap_fetchstructure($this->conn, $identifier, $options);
		if (!$structure) {
			// Error fetching structure
			error_log("IMAPEmailChecker: Failed to fetch structure for identifier {$identifier} (isUid: " . ($isUid ? 'true' : 'false') . ")");
			return false;
		}

		$messageParts = [];
		$hasHtml = false;

		// Closure to recursively process parts
		$decodePart = function ($part, string $partNum) use (&$decodePart, $identifier, $options, &$messageParts, &$hasHtml) {
			// skip attachments explicitly marked
			if (!empty($part->disposition) && strtolower($part->disposition) === 'attachment') {
				return;
			}

			// Fetch the body part using the correct identifier type
			$raw = imap_fetchbody($this->conn, $identifier, $partNum, $options | FT_PEEK); // Use FT_PEEK to not mark as read
			if ($raw === false) {
				// Error fetching part, continue if possible
				error_log("IMAPEmailChecker: Failed to fetch body part {$partNum} for identifier {$identifier}");
				return;
			}

			// decode by encoding type
			$raw = match ($part->encoding) {
				ENC8BIT => imap_utf8($raw), // Use imap_utf8 for 8bit which might contain UTF-8
				ENCBINARY => imap_binary($raw),
				ENCBASE64 => imap_base64($raw),
				ENCQUOTEDPRINTABLE => quoted_printable_decode($raw),
				// ENC7BIT, ENCOTHER -> leave as is
				default => $raw
			};

			// convert charset to utf8
			$raw = $this->normalizeToUtf8($raw, $part);

			// recurse if multipart
			if (!empty($part->parts) && is_array($part->parts)) {
				foreach ($part->parts as $idx => $subPart) {
					// Pass the correct identifier and options down
					$decodePart($subPart, $partNum . '.' . ($idx + 1));
				}
				return; // Don't process the multipart container itself
			}

			// Process leaf parts (non-multipart)
			// Choose HTML over plain text
			$subtype = strtolower($part->subtype ?? '');
			$type = $part->type ?? -1; // Get the integer type, default to -1 if missing

			// Check primary type (TYPETEXT = 0) and subtype
			if ($type === TYPETEXT) { // Compare with integer constant
				if ($subtype === 'html') {
					$messageParts = [$raw]; // Prioritize HTML, replace any plain text found so far
					$hasHtml = true;
				} elseif ($subtype === 'plain') {
					if (!$hasHtml) { // Only add plain text if we haven't found HTML yet
						$messageParts[] = $raw;
					}
				}
				// Ignore other text subtypes for the main body
			}
			// If it's not text (e.g., image, application) and not explicitly an attachment,
			// it might be an inline element handled by checkForAttachments, so ignore here.
		};

		// Start recursion
		if (!empty($structure->parts) && is_array($structure->parts)) {
			// Multipart message
			foreach ($structure->parts as $i => $p) {
				$decodePart($p, (string)($i + 1));
			}
		} else {
			// Single part message (or structure describes the main part directly)
			$decodePart($structure, '1');
		}

		$body = trim(implode("\n", $messageParts));
		return $body !== '' ? $body : false; // Return false if body is effectively empty
	}


	/**
	 * Ensure the given raw part data is UTF‑8.
	 *
	 * 1) Reads any declared charset param from the part’s parameters or dparameters
	 * 2) Falls back to mb_detect_encoding() against common encodings
	 * 3) Converts to UTF‑8 if needed
	 *
	 * @param string $raw  The decoded-but-not‑yet‑re‑encoded part body.
	 * @param object $part The MIME part object from imap_fetchstructure().
	 * @return string      The UTF‑8–encoded text.
	 */
	private function normalizeToUtf8(string $raw, object $part): string
	{
		// look for a declared charset
		$charset = null;
		// Check parameters first (often used for text parts)
		if (!empty($part->parameters) && is_array($part->parameters)) {
			foreach ($part->parameters as $p) {
				if (isset($p->attribute) && strcasecmp($p->attribute, 'charset') === 0) {
					$charset = $p->value;
					break;
				}
			}
		}
		// Check dparameters if not found in parameters (often used for attachments/disposition)
		if (!$charset && !empty($part->dparameters) && is_array($part->dparameters)) {
			foreach ($part->dparameters as $p) {
				if (isset($p->attribute) && strcasecmp($p->attribute, 'charset') === 0) {
					$charset = $p->value;
					break;
				}
			}
		}

		// fallback auto‑detect if no charset declared or if it's something weird
		$needsConversion = false;
		if ($charset) {
			// Normalize charset name (e.g., remove quotes, handle aliases if necessary)
			$charset = strtoupper(trim($charset, " \t\n\r\0\x0B\"'"));
			if ($charset !== 'UTF-8' && $charset !== 'DEFAULT' && $charset !== 'US-ASCII') { // Assume US-ASCII is compatible
				$needsConversion = true;
			} elseif ($charset === 'DEFAULT') { // 'DEFAULT' often means ISO-8859-1 or system default
				$charset = 'ISO-8859-1'; // A common guess for 'DEFAULT'
				$needsConversion = true;
			}
		} else {
			// Auto-detect if no charset was specified
			// Limit detection order to prevent false positives if possible
			$detected = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'WINDOWS-1252'], true);
			if ($detected && $detected !== 'UTF-8') {
				$charset = $detected;
				$needsConversion = true;
			} elseif (!$detected && !mb_check_encoding($raw, 'UTF-8')) {
				// If detection fails AND it's not valid UTF-8, guess ISO-8859-1
				$charset = 'ISO-8859-1';
				$needsConversion = true; // Attempt conversion
			}
		}


		// convert if needed and possible
		if ($needsConversion && $charset) {
			// Use @ to suppress errors if conversion fails
			$converted = @mb_convert_encoding($raw, 'UTF-8', $charset);
			if ($converted !== false) {
				$raw = $converted;
			}
			// If conversion fails, $raw remains unchanged (original encoding)
		}

		// Final check: Ensure the final string is valid UTF-8, replacing invalid sequences
		// This helps clean up potential issues from failed conversions or mixed encodings.
		if (!mb_check_encoding($raw, 'UTF-8')) {
			$raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
		}


		return $raw;
	}


	/**
	 * Checks for attachments and inline images in the message, identified by message number or UID.
	 * This method finds ALL attachments and inline parts. Filtering happens later in processMessage.
	 *
	 * @param int $identifier The message sequence number or UID.
	 * @param bool $isUid Whether the identifier is a UID (true) or message number (false).
	 * @return array An array of attachments/inline parts with keys: filename, content, type, mime_type, disposition
	 *               and optionally content_id for inline images.
	 */
	private function checkForAttachments(int $identifier, bool $isUid = false): array
	{
		$options = $isUid ? FT_UID : 0;
		$structure = imap_fetchstructure($this->conn, $identifier, $options);
		$attachments = [];

		// Need to handle case where the top-level structure itself might be an attachment (rare)
		// For now, focus on parts as that's the common case.

		if (isset($structure->parts) && is_array($structure->parts)) {
			$flattenParts = function ($parts, $prefix = '') use (&$flattenParts, &$attachments, $identifier, $options) {
				foreach ($parts as $i => $part) {
					$partNum = $prefix . ($i + 1);

					// Check for filename in parameters or dparameters
					$filename = null;
					// Prefer filename from Content-Disposition dparameters
					if (!empty($part->dparameters)) {
						foreach ($part->dparameters as $param) {
							if (isset($param->attribute) && strcasecmp($param->attribute, 'filename') === 0) {
								$filename = $param->value;
								break;
							}
						}
					}
					// Fallback to name from Content-Type parameters if filename not found
					if ($filename === null && !empty($part->parameters)) {
						foreach ($part->parameters as $param) {
							if (isset($param->attribute) && strcasecmp($param->attribute, 'name') === 0) {
								$filename = $param->value;
								break;
							}
						}
					}

					// Decode filename if MIME encoded
					if ($filename !== null) {
						$filename = $this->decodeHeaderValue($filename); // Use the same decoder as headers
					}


					// Determine if it's an attachment or inline based on disposition or filename presence
					$disposition = strtolower($part->disposition ?? '');
					$isAttachment = ($disposition === 'attachment');
					$isInline = ($disposition === 'inline');
					$contentId = null;
					if ($isInline && isset($part->id)) {
						$contentId = trim($part->id, '<>');
					}

					// Consider it an attachment if disposition is 'attachment' OR
					// if it's not 'inline', not text/plain, not text/html, not multipart/*, AND has a filename.
					$type = $part->type ?? -1; // Get integer type
					$subtype = strtolower($part->subtype ?? '');

					if (!$isAttachment && !$isInline && $filename !== null) {
						// Check if it's NOT typical viewable content like text or a container
						if ($type !== TYPETEXT || ($subtype !== 'plain' && $subtype !== 'html')) {
							if ($type !== TYPEMULTIPART && $type !== TYPEMESSAGE) { // Messages can contain parts, don't treat container as attachment
								$isAttachment = true; // Treat as attachment if it has a filename and isn't viewable body content or a container
							}
						}
					}


					if ($isAttachment || $isInline) {
						// Fetch content using the correct identifier type
						$content = imap_fetchbody($this->conn, $identifier, $partNum, $options | FT_PEEK);
						if ($content === false) {
							error_log("IMAPEmailChecker: Failed to fetch attachment/inline body part {$partNum} for identifier {$identifier}");
							continue; // Skip if fetching failed
						}

						$content = match($part->encoding) {
							ENCBASE64 => imap_base64($content),
							ENCQUOTEDPRINTABLE => quoted_printable_decode($content),
							// Assume others are already decoded or don't need decoding (binary, 8bit, 7bit)
							default => $content
						};

						// Construct full MIME type string
						$mimeType = $this->getMimeTypeString($type, $subtype);

						$attachmentData = [
							'filename' => $filename ?? 'unknown_' . $partNum, // Provide default if no filename found
							'content'  => $content,
							'type'     => $subtype, // e.g., JPEG, PNG, PDF (legacy/simple type)
							'mime_type'=> $mimeType, // Full MIME type e.g. image/jpeg
							'disposition' => $disposition ?: ($isAttachment ? 'attachment' : ($isInline ? 'inline' : null)), // Store disposition
						];

						if ($isInline && $contentId !== null) {
							$attachmentData['content_id'] = $contentId;
						}

						$attachments[] = $attachmentData;
					}

					// Recurse into subparts if they exist (e.g., multipart/related, message/rfc822)
					if (!empty($part->parts) && is_array($part->parts)) {
						// Pass the part number prefix correctly
						$flattenParts($part->parts, $partNum . '.');
					}
				}
			};

			$flattenParts($structure->parts);
		}
		// Note: This doesn't handle attachments in non-multipart messages well,
		// but those are less common. A single-part message that *is* an attachment
		// would likely be handled by the calling context checking the main structure type.

		return $attachments;
	}

	/**
	 * Helper to convert IMAP type/subtype constants/strings to a MIME type string.
	 *
	 * @param int $type The integer type constant (e.g., TYPETEXT).
	 * @param string $subtype The subtype string (e.g., 'html').
	 * @return string The full MIME type string (e.g., 'text/html').
	 */
	private function getMimeTypeString(int $type, string $subtype): string
	{
		$primaryType = match ($type) {
			TYPETEXT => 'text',
			TYPEMULTIPART => 'multipart',
			TYPEMESSAGE => 'message',
			TYPEAPPLICATION => 'application',
			TYPEAUDIO => 'audio',
			TYPEIMAGE => 'image',
			TYPEVIDEO => 'video',
			TYPEMODEL => 'model',
			default => 'application', // Default for TYPEOTHER or unknown
		};
		// Subtype might be empty for some types, default to octet-stream if application
		if (empty($subtype)) {
			return $primaryType === 'application' ? 'application/octet-stream' : $primaryType;
		}
		return $primaryType . '/' . strtolower($subtype);
	}


	/**
	 * Replaces CID references in HTML with corresponding inline image data URIs.
	 *
	 * Searches for "cid:" references in the HTML and replaces them with base64-encoded
	 * data URIs using the matching inline image attachment. Uses the FULL list of attachments found.
	 *
	 * @param string $html The original HTML content.
	 * @param array $allAttachments The list of ALL attachments/inline parts from checkForAttachments().
	 * @return string The HTML content with embedded inline images.
	 */
	private function embedInlineImages(string $html, array $allAttachments): string
	{
		if (empty($allAttachments) || strpos($html, 'cid:') === false) {
			return $html; // No attachments or no CIDs found, return original HTML
		}

		return preg_replace_callback('/(["\']?)cid:([^"\'\s>]+)(["\']?)/i', function ($matches) use ($allAttachments) {
			$cid = $matches[2]; // The actual Content-ID
			$quoteStart = $matches[1]; // Leading quote, if any
			$quoteEnd = $matches[3]; // Trailing quote, if any

			foreach ($allAttachments as $attachment) {
				// Check if it's inline and the content_id matches
				if (isset($attachment['content_id']) && $attachment['content_id'] === $cid && ($attachment['disposition'] ?? null) === 'inline') {
					// Use the full mime_type calculated earlier.
					$mime = $attachment['mime_type'] ?? 'application/octet-stream'; // Fallback needed?
					if (strpos($mime, '/') === false) { // Simple check if it looks like a full mime type
						// If only subtype was stored previously, reconstruct (e.g., image/jpeg)
						// This depends on how mime_type was stored; best to store the full one.
						$mime = 'image/' . strtolower($mime); // Assuming image if only subtype stored
					}
					$base64 = base64_encode($attachment['content']);
					// Return the data URI, preserving original quotes if they existed
					return $quoteStart . "data:" . $mime . ";base64," . $base64 . $quoteEnd;
				}
			}
			// Return the original match (e.g., "cid:some_id") if no corresponding inline attachment is found.
			return $matches[0];
		}, $html);
	}


	/**
	 * Formats an address object from imap_headerinfo into a string.
	 *
	 * @param stdClass $addr The address object (must have mailbox and host properties).
	 * @return string The formatted email address "mailbox@host". Returns empty string if invalid.
	 */
	private function formatAddress(stdClass $addr): string
	{
		// Check if mailbox and host exist and are not empty
		if (isset($addr->mailbox) && $addr->mailbox !== '' && isset($addr->host) && $addr->host !== '') {
			// Handle potential encoding in mailbox part (though less common than personal)
			$mailbox = $this->decodeHeaderValue($addr->mailbox);
			// Hostnames generally shouldn't be MIME encoded, but decode just in case? Usually not needed.
			// $host = $this->decodeHeaderValue($addr->host);
			$host = $addr->host;
			return $mailbox . "@" . $host;
		}
		// Handle special case: undiscosed recipients, group syntax etc.
		// Example: "undisclosed-recipients:;" -> mailbox will be "undisclosed-recipients" host will be null/missing
		// Example: "Group Name: member1@a.com, member2@b.com;" -> mailbox="Group Name", host=null/missing
		// For now, we only return valid mailbox@host formats.
		return '';
	}

	/**
	 * Formats an array of address objects from imap_headerinfo into an array of strings.
	 * Handles nested groups if present.
	 *
	 * @param array|null $addresses Array of address objects (e.g., $header->to).
	 * @return array An array of formatted email address strings.
	 */
	private function formatAddressList(?array $addresses): array
	{
		$list = [];
		if (is_array($addresses)) {
			foreach ($addresses as $addr) {
				if ($addr instanceof stdClass) {
					// Check for group syntax (mailbox set, host missing) - recurse if needed, though imap_headerinfo usually flattens?
					// Let's assume imap_headerinfo provides a flat list of final recipients.
					$formatted = $this->formatAddress($addr);
					if ($formatted !== '') {
						$list[] = $formatted;
					}
					// If you needed to handle RFC822 group syntax explicitly, you'd check for missing host
					// and potentially iterate over a 'groupaddresses' property if the library provided it.
				}
			}
		}
		return $list;
	}

	/**
	 * Decodes a potentially MIME-encoded header value.
	 * Uses mb_decode_mimeheader.
	 *
	 * @param string|null $value The raw header value.
	 * @return string The decoded string, or empty string if input is null.
	 */
	private function decodeHeaderValue(?string $value): string
	{
		if ($value === null || $value === '') {
			return '';
		}
		// Suppress errors during decoding as some headers might be malformed
		$decoded = @mb_decode_mimeheader($value);
		// Ensure the result is UTF-8, as mb_decode_mimeheader respects internal encoding
		if (!mb_check_encoding($decoded, 'UTF-8')) {
			// If it's not UTF-8, try to convert from a common fallback like ISO-8859-1
			$converted = @mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
			if ($converted !== false) {
				return $converted;
			}
			// If conversion fails, return the original decoded string, potentially with encoding issues
		}
		return $decoded;

	}


    /**
     * Fetches and processes specific email messages by their identifiers (UIDs or Sequence Numbers).
     *
     * This method retrieves the full details for the given message identifiers.
     * Note: This method does NOT update the main `$this->messages` or `$this->lastuid` properties
     * of the class, as it's intended for fetching specific messages on demand, not
     * as part of a sequence like the check* methods.
     *
     * @param array $identifiers An array of integer UIDs or message sequence numbers.
     * @param bool  $isUid       True if the identifiers are UIDs (default), False if they are sequence numbers.
     * @return array An associative array where keys are the requested identifiers (if found)
     *               and values are the processed message data arrays (see Message Array Structure).
     *               Messages that could not be processed (e.g., invalid ID) will be omitted.
     */
    public function fetchMessagesByIds(array $identifiers, bool $isUid = true): array
    {
        $fetchedMessages = [];
        if (empty($identifiers)) {
            return $fetchedMessages; // Return empty if no IDs provided
        }

        // Ensure identifiers are integers
        $validIdentifiers = array_filter($identifiers, 'is_int');
        if (count($validIdentifiers) !== count($identifiers)) {
             error_log("IMAPEmailChecker: Non-integer identifier provided to fetchMessagesByIds. Only integers processed.");
             // Continue with only the valid integers
        }


        foreach ($validIdentifiers as $id) {
             if ($id <= 0) continue; // Skip potentially invalid IDs

            $processed = $this->processMessage($id, $isUid); // Call the private method
            if ($processed !== null) {
                // Use the *actual* UID from the processed message as the key for consistency,
                // but only if we were originally requesting by UID. If requesting by MsgNo,
                // using the original MsgNo ($id) as key might be more intuitive here.
                $key = $isUid ? ($processed['uid'] ?? $id) : $id;
                $fetchedMessages[$key] = $processed;
            } else {
                error_log("IMAPEmailChecker: Failed to process message identifier {$id} (isUid: " . ($isUid ? 'true' : 'false') . ") during fetchMessagesByIds");
            }
        }

        return $fetchedMessages;
    }
	

	/**
	 * Processes an individual email message and returns its data as an associative array.
	 * Uses either the message sequence number or the UID.
	 *
	 * @param int $identifier The message number or UID to process.
	 * @param bool $isUid True if $identifier is a UID, false if it's a message number.
	 * @return array|null The processed message data, or null on failure.
	 */
	private function processMessage(int $identifier, bool $isUid = false): ?array
	{
		// 1. Get Message Number and UID
		$msgNo = $isUid ? imap_msgno($this->conn, $identifier) : $identifier;
		if (!$msgNo) {
			// Failed to get message number from UID
			error_log("IMAPEmailChecker: Failed to get message number for UID {$identifier}");
			return null;
		}
		// Get UID if we started with message number, or confirm the one we have
		$uid = $isUid ? $identifier : imap_uid($this->conn, $msgNo);
		if (!$uid) {
			// Failed to get UID (less likely if msgNo is valid, but check anyway)
			error_log("IMAPEmailChecker: Failed to get UID for message number {$msgNo}");
			return null;
		}

		// 2. Fetch Header Info using Message Number
		// Use FT_PEEK here as well to avoid marking as read just by fetching headers
		$header = imap_headerinfo($this->conn, $msgNo); // Note: imap_headerinfo doesn't have FT_PEEK option directly
		if (!$header || !($header instanceof stdClass)) {
			error_log("IMAPEmailChecker: Failed to fetch header info for message number {$msgNo} (UID: {$uid})");
			return null;
		}

		// 3. Decode Body using original identifier and type
		$messageBody = $this->decodeBody($identifier, $isUid);
		// Allow processing even if body decoding fails (might just be headers/attachments)
		// if ($messageBody === false) {
		//     error_log("IMAPEmailChecker: Failed to decode body for message number {$msgNo} (UID: {$uid})");
		//     // return null;
		// }

		// 4. Check for ALL Attachments AND Inline parts using original identifier and type
		$allAttachments = $this->checkForAttachments($identifier, $isUid);

		// 5. Embed Inline Images (if body exists) using the FULL list of attachments/inline parts
		if ($messageBody !== false) {
			$messageBody = $this->embedInlineImages($messageBody, $allAttachments);
		} else {
			$messageBody = ''; // Ensure body is a string even if decoding failed
		}

		// 6. Assemble Processed Data
		$processed = [];
		$processed['uid'] = $uid; // Use UID as the primary key
		$processed['message_number'] = $msgNo;
		// Clean message-id: remove angle brackets if present
		$messageIdRaw = isset($header->message_id) ? trim($header->message_id) : null;
		$processed['message_id'] = $messageIdRaw ? trim($messageIdRaw, '<>') : null;
		$processed['subject'] = isset($header->subject) ? $this->decodeHeaderValue($header->subject) : '';
		$processed['message_body'] = $messageBody;
		$processed['date'] = isset($header->date) ? $header->date : null; // Raw date string
		// Try parsing the date for easier use later, fallback to raw string
		$processed['datetime'] = null;
		if (isset($header->udate) && $header->udate > 0) { // Check udate is valid
			$processed['datetime'] = (new DateTime())->setTimestamp($header->udate);
		} elseif (isset($header->date)) {
			try {
				// Attempt parsing with DateTime, might fail on weird formats
				$processed['datetime'] = new DateTime($header->date);
			} catch (\Exception $e) {
				// Fallback: try parsing with strtotime as it's more lenient
				$ts = strtotime($header->date);
				if ($ts !== false) {
					$processed['datetime'] = (new DateTime())->setTimestamp($ts);
				} else {
					$processed['datetime'] = null; // Parsing failed completely
				}
			}
		}


		// Process Addresses using helper methods
		$processed['to'] = isset($header->to) ? $this->formatAddressList($header->to) : [];
		$processed['tocount'] = count($processed['to']);

		$processed['cc'] = isset($header->cc) ? $this->formatAddressList($header->cc) : [];
		$processed['cccount'] = count($processed['cc']);

		$processed['bcc'] = isset($header->bcc) ? $this->formatAddressList($header->bcc) : [];
		$processed['bcccount'] = count($processed['bcc']);

		// From address (prefer 'from', fallback to 'sender')
		$fromAddress = '';
		$fromDisplay = ''; // Full "Personal <mailbox@host>"
		$fromSource = $header->from ?? ($header->sender ?? null); // Prefer 'from'
		if (!empty($fromSource) && is_array($fromSource)) {
			// Use the first address in the array
			$fromObj = $fromSource[0];
			if ($fromObj instanceof stdClass) {
				$fromAddress = $this->formatAddress($fromObj);
				// Construct display name (handle potential encoding in 'personal')
				$personal = isset($fromObj->personal) ? $this->decodeHeaderValue($fromObj->personal) : '';
				if ($personal !== '' && $fromAddress !== '') {
					$fromDisplay = $personal . ' <' . $fromAddress . '>';
				} elseif ($fromAddress !== '') {
					// If no personal name, display is just the address
					$fromDisplay = $fromAddress;
				} elseif ($personal !== '') {
					// If personal name but no valid address (e.g., group syntax), display is just the name
					$fromDisplay = $personal;
				}
			}
		}
		// Fallback using the pre-formatted 'fromaddress'/'senderaddress' if our parsing failed
		if ($fromDisplay === '' && isset($header->fromaddress)) {
			$fromDisplay = $this->decodeHeaderValue($header->fromaddress);
		} elseif ($fromDisplay === '' && isset($header->senderaddress)) {
			$fromDisplay = $this->decodeHeaderValue($header->senderaddress);
		}
		// Try to extract email from display string if address still missing
		if ($fromAddress === '' && $fromDisplay !== '') {
			if (preg_match('/<([^>]+@[^>]+)>/', $fromDisplay, $matches)) {
				$fromAddress = $matches[1];
			} elseif (strpos($fromDisplay, '@') !== false && strpos($fromDisplay, '<') === false) {
				// Assume display *is* the address if no brackets and contains @
				$fromAddress = $fromDisplay;
			}
		}


		$processed['fromaddress'] = $fromAddress; // Just email@domain.com
		$processed['from'] = $fromDisplay; // Full display string

		// Searches the subject for a #N number and saves it as a "bid"
		$thisbid = null; // Use null if not found
		if ($processed['subject'] !== '' && preg_match("/#(\d+)/", $processed['subject'], $matches)) {
			// Use intval for the numeric bid
			$thisbid = intval($matches[1]);
		}
		$processed['bid'] = $thisbid;

		// Unseen flag ('U' = unseen, ' ' = recent but unseen)
		// Check 'Recent' flag as well ('N' = recent, 'R' = read, ' ' = not recent)
		// Unseen = Unseen flag is 'U' OR (Recent flag is 'N' AND Seen flag is not set)
		$isUnseen = (isset($header->Unseen) && trim($header->Unseen) === 'U');
		$isRecent = (isset($header->Recent) && trim($header->Recent) === 'N');
		$isSeen = (isset($header->Seen) && trim($header->Seen) === 'S'); // Check Seen flag explicitly

		// Consider unseen if explicitly marked 'U', or if Recent='N' and not explicitly Seen='S'
		$processed['unseen'] = $isUnseen || ($isRecent && !$isSeen);


		// Filter attachments to exclude inline images (those with content_id), mimicking old behavior.
		$filteredAttachments = array_filter($allAttachments, function($attachment) {
			// Check if 'content_id' key exists and is not null/empty (robust check)
			return !isset($attachment['content_id']) || empty($attachment['content_id']);
		});
		// Assign the filtered list, resetting array keys for cleaner JSON/output if needed
		$processed['attachments'] = array_values($filteredAttachments);


		return $processed;
	}


	/**
	 * Retrieves all emails from the mailbox.
	 *
	 * For each email, it decodes the body, retrieves attachments, embeds inline images,
	 * and extracts header details. Messages are stored keyed by UID.
	 *
	 * @return array An array of processed email data, keyed by UID.
	 */
	public function checkAllEmail(): array
	{
		$msg_count = imap_num_msg($this->conn);
		$this->messages = []; // Reset messages
		$current_last_uid = $this->lastuid; // Track the highest UID encountered

		if ($msg_count > 0) {
			// Fetch overview to get UIDs efficiently
			// Fetching overview doesn't mark messages as read
			$overviews = imap_fetch_overview($this->conn, "1:{$msg_count}", 0);

			if ($this->validateResults($overviews)) {
				foreach ($overviews as $overview) {
					if (!isset($overview->uid)) continue; // Skip if overview lacks UID somehow

					$uid = (int)$overview->uid;
					// Process using the UID for consistency and because overview gives it to us
					$processed = $this->processMessage($uid, true);
					if ($processed !== null) {
						$this->messages[$uid] = $processed;
						if ($uid > $current_last_uid) {
							$current_last_uid = $uid;
						}
					} else {
						// Log processing failure
						error_log("IMAPEmailChecker: Failed to process message UID {$uid} during checkAllEmail");
					}
				}
			} else {
				// Fallback: Iterate by message number if overview fails (less efficient)
				// error_log("IMAPEmailChecker: Failed to fetch overview, falling back to message number iteration.");
				for ($i = 1; $i <= $msg_count; $i++) {
					// Process by message number, will fetch UID inside processMessage
					$processed = $this->processMessage($i, false);
					if ($processed !== null && isset($processed['uid'])) {
						$uid = $processed['uid'];
						$this->messages[$uid] = $processed; // Store by UID
						if ($uid > $current_last_uid) {
							$current_last_uid = $uid;
						}
					} else {
						// Log processing failure?
						error_log("IMAPEmailChecker: Failed to process message number {$i} during checkAllEmail fallback");
					}
				}
			}
		}

		$this->lastuid = $current_last_uid; // Update last processed UID
		return $this->messages;
	}


	/**
	 * Retrieves emails from the mailbox since a specified date.
	 *
	 * The date must be provided as a DateTime object, and the method returns emails from that day onward.
	 * Messages are stored keyed by UID.
	 *
	 * @param DateTime $date The starting date.
	 * @return bool|array False on failure or an array of emails keyed by UID.
	 */
	public function checkSinceDate(DateTime $date): bool|array
	{
		if (!isset($date)) {
			return false;
		}
		$this->messages = []; // Reset messages for this specific check
		$current_last_uid = $this->lastuid;

		// Format date for IMAP SINCE command (RFC 3501 format: d-M-Y)
		$thedate = $date->format('d-M-Y'); // e.g., 01-Jan-2023
		$searchCriteria = "SINCE \"{$thedate}\"";

		// Search returns UIDs because of SE_UID. This doesn't mark messages read.
		$uids = imap_search($this->conn, $searchCriteria, SE_UID);

		if ($uids === false) {
			// An error occurred during search
			error_log("IMAPEmailChecker: imap_search failed for criteria '{$searchCriteria}'");
			return false;
		}

		if (empty($uids)) {
			// No messages found since that date, return empty array
			return $this->messages;
		}

		// Sort UIDs just in case the server doesn't return them ordered
		sort($uids, SORT_NUMERIC);

		foreach ($uids as $uid) {
			$uid = (int)$uid;
			$processed = $this->processMessage($uid, true); // Process by UID
			if ($processed !== null) {
				$this->messages[$uid] = $processed;
				if ($uid > $current_last_uid) {
					$current_last_uid = $uid;
				}
			} else {
				error_log("IMAPEmailChecker: Failed to process message UID {$uid} during checkSinceDate");
			}
		}

		$this->lastuid = $current_last_uid; // Update last processed UID
		return $this->messages;
	}


	/**
	 * Retrieves emails from the mailbox with UIDs greater than the specified UID.
	 * Messages are stored keyed by UID.
	 *
	 * @param int $uid The UID after which to fetch messages (exclusive).
	 * @return bool|array False on failure or an array of emails keyed by UID.
	 */
	public function checkSinceLastUID(int $uid): bool|array
	{
		$this->messages = []; // Reset messages for this specific check
		$current_last_uid = $uid; // Start tracking from the provided UID

		// Search for UIDs greater than the provided one.
		// The range {$startUidPlusOne}:* means all UIDs >= $startUidPlusOne.
		// Use '*' for the maximum possible UID.
		$startUidPlusOne = $uid + 1;
		$searchRange = "{$startUidPlusOne}:*";

		// Fetch overview for the range. This is generally efficient and doesn't mark read.
		$overviewList = imap_fetch_overview($this->conn, $searchRange, FT_UID);

		if ($overviewList === false) {
			// An error occurred during fetch_overview
			error_log("IMAPEmailChecker: imap_fetch_overview failed for range '{$searchRange}'");
			// Don't update lastuid if the check failed
			return false; // Indicate failure
		}

		if (empty($overviewList)) {
			// No messages found with UID > $uid, return empty array
			// $this->lastuid remains unchanged from the input $uid in this case.
			return $this->messages;
		}

		foreach ($overviewList as $ov) {
			// Ensure we have a valid UID from the overview
			if (!isset($ov->uid)) continue;

			$currentUid = (int)$ov->uid;
			// Double check it's actually greater than the requested UID (should be based on search range)
			if ($currentUid <= $uid) continue;

			$processed = $this->processMessage($currentUid, true); // Process by UID
			if ($processed !== null) {
				$this->messages[$currentUid] = $processed;
				// Update the highest UID seen in this batch
				if ($currentUid > $current_last_uid) {
					$current_last_uid = $currentUid;
				}
			} else {
				error_log("IMAPEmailChecker: Failed to process message UID {$currentUid} during checkSinceLastUID");
			}
		}

		// Update the class property to the highest UID processed in this run
		$this->lastuid = $current_last_uid;

		return $this->messages;
	}

	
	/**
	 * Retrieves unread emails (\Unseen flag) from the mailbox.
	 *
	 * For each unread email, it decodes the body, retrieves attachments, embeds inline images,
	 * and extracts header details. Messages are stored keyed by UID.
	 * This method uses the 'UNSEEN' search criterion.
	 *
	 * @return bool|array False on search failure or an array of unread emails keyed by UID.
	 *                    Returns an empty array if no unread emails are found.
	 */
	public function checkUnreadEmails(): bool|array
	{
		$this->messages = []; // Reset messages for this specific check
		// Keep track of the highest UID encountered in this batch to update the class property
		$current_last_uid = $this->lastuid;

		// Search for messages with the \Unseen flag. SE_UID returns UIDs.
		// This search operation itself does not mark messages as read.
		$uids = imap_search($this->conn, 'UNSEEN', SE_UID);

		if ($uids === false) {
			// An error occurred during search
			error_log("IMAPEmailChecker: imap_search failed for criteria 'UNSEEN'. Error: " . imap_last_error());
			return false; // Indicate failure
		}

		if (empty($uids)) {
			// No unread messages found, return empty array
			// $this->lastuid remains unchanged in this case.
			return $this->messages;
		}

		// Sort UIDs just in case the server doesn't return them ordered
		sort($uids, SORT_NUMERIC);

		foreach ($uids as $uid) {
			$uid = (int)$uid; // Ensure it's an integer
			$processed = $this->processMessage($uid, true); // Process by UID
			if ($processed !== null) {
				$this->messages[$uid] = $processed;
				// Update the highest UID seen in this batch
				if ($uid > $current_last_uid) {
					$current_last_uid = $uid;
				}
			} else {
				error_log("IMAPEmailChecker: Failed to process unread message UID {$uid} during checkUnreadEmails");
			}
		}

		// Update the class property to the highest UID processed in this run.
		// This ensures that subsequent checkSinceLastUID calls work correctly,
		// even if they happen after an checkUnreadEmails call.
		$this->lastuid = $current_last_uid;

		return $this->messages;
	}


	/**
	 * Sets or clears the read status (\Seen flag) for one or more emails by their UIDs.
	 *
	 * @param array $uids         An array of integer UIDs to modify.
	 *                            Example: [123, 456] or [$singleUid]
	 * @param bool  $markAsRead   If true, sets the \Seen flag (marks as read).
	 *                            If false, clears the \Seen flag (marks as unread).
	 * @return bool True if the operation was successful for all specified UIDs, false otherwise.
	 */
	public function setMessageReadStatus(array $uids, bool $markAsRead): bool
	{
		if (empty($uids)) {
			// No UIDs provided, nothing to do. Consider this success.
			return true;
		}

		// Basic validation: Ensure all UIDs are positive integers
		$validatedUids = [];
		foreach ($uids as $uid) {
			// Ensure it's an integer and positive
			if (filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
				$validatedUids[] = (int)$uid;
			} else {
				error_log("IMAPEmailChecker: Invalid UID provided in setMessageReadStatus: " . var_export($uid, true));
				// Fail the whole operation if any UID is invalid
				return false;
			}
		}

		// If validation removed all UIDs (e.g., all were invalid)
		if (empty($validatedUids)) {
			error_log("IMAPEmailChecker: No valid UIDs remaining after validation in setMessageReadStatus.");
			return false;
		}

		// Convert the array of validated UIDs into a comma-separated string sequence
		$uidSequence = implode(',', $validatedUids);
		$flag = "\\Seen"; // The flag we are manipulating (needs double backslash in PHP string)

		$success = false;

		// Use imap_setflag_full to mark as read, imap_clearflag_full to mark as unread
		if ($markAsRead) {
			// Set the \Seen flag
			$success = imap_setflag_full($this->conn, $uidSequence, $flag, ST_UID);
			if (!$success) {
				error_log("IMAPEmailChecker: Failed to set {$flag} flag (mark read) for UIDs: {$uidSequence}. Error: " . imap_last_error());
			}
		} else {
			// Clear the \Seen flag
			$success = imap_clearflag_full($this->conn, $uidSequence, $flag, ST_UID);
			if (!$success) {
				error_log("IMAPEmailChecker: Failed to clear {$flag} flag (mark unread) for UIDs: {$uidSequence}. Error: " . imap_last_error());
			}
		}

		return $success;
	}


	/**
	 * Deletes an email from the mailbox by UID.
	 *
	 * This method marks the specified email for deletion and then expunges the mailbox.
	 *
	 * @param int $uid The UID of the email to delete.
	 * @return bool True on success, false on failure.
	 */
	public function deleteEmail(int $uid): bool
	{
		// Mark the message for deletion using its UID
		if (!imap_delete($this->conn, (string)$uid, FT_UID)) {
			error_log("IMAPEmailChecker: Failed to mark UID {$uid} for deletion.");
			return false;
		}

		// Permanently remove emails marked for deletion from the current mailbox.
		if (!imap_expunge($this->conn)) {
			error_log("IMAPEmailChecker: Failed to expunge mailbox after deleting UID {$uid}.");
			// Note: Deletion might have succeeded even if expunge returns false (e.g., if mailbox was empty)
			// Consider checking imap_errors() or imap_last_error() for more details.
			// For simplicity, return false if expunge fails.
			return false;
		}
		return true;
	}

	/**
	 * Archives an email by moving it to a specified folder by UID.
	 *
	 * This method moves the email from the current mailbox to the target archive folder
	 * and then expunges the mailbox to remove the email from its original location.
	 *
	 * @param int    $uid The UID of the email to archive.
	 * @param string $archiveFolder The target folder where the email should be moved (default is "Archive").
	 * @return bool True on success, false on failure.
	 */
	public function archiveEmail(int $uid, string $archiveFolder = 'Archive'): bool
	{
		// Ensure the archive folder exists? (Optional, imap_mail_move might fail gracefully or create it depending on server)
		// You could add a check like: imap_list($this->conn, imap_utf7_encode("{".$this->host."}".$archiveFolder));

		// Move the email to the archive folder using UID. CP_UID implies FT_UID for the sequence.
		if (!imap_mail_move($this->conn, (string)$uid, $archiveFolder, CP_UID)) {
			error_log("IMAPEmailChecker: Failed to move UID {$uid} to folder '{$archiveFolder}'. Error: " . imap_last_error());
			return false;
		}

		// Remove the moved email from the current mailbox.
		if (!imap_expunge($this->conn)) {
			error_log("IMAPEmailChecker: Failed to expunge mailbox after archiving UID {$uid}.");
			// Similar to delete, consider checking errors. Return false for simplicity.
			return false;
		}
		return true;
	}
}