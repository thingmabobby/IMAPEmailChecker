<?php
declare(strict_types=1);

namespace IMAPEmailChecker;

use DateTime;

/**
 * Class IMAPEmailChecker
 *
 * A class to pull emails from a mailbox using IMAP. It provides methods to retrieve emails,
 * decode email bodies (including inline images), and extract attachments.
 *
 * Public Properties:
 *  - lastuid: The last UID processed.
 *  - messages: An associative array containing the email messages found.
 *
 * Usage:
 *  - checkAllEmail() - returns all emails in the mailbox.
 *  - checkSinceDate(DateTime $date) - returns emails since the given date.
 *  - checkSinceLastUID(int $uid) - returns emails since the specified UID.
 */
class IMAPEmailChecker
{
    /**
     * @var resource The IMAP connection resource.
     */
    private $conn;

    /**
     * @var int The UID of the last processed email.
     */
    public int $lastuid = 0;

    /**
     * @var array The list of messages fetched.
     */
    public array $messages = [];

    /**
     * IMAPEmailChecker constructor.
     *
     * @param resource $connection The IMAP connection resource.
     * @param int $lastuid The last UID processed.
     * @param array $messages An initial messages array.
     */
    public function __construct($connection, int $lastuid = 0, array $messages = [])
    {
        $this->conn = $connection;
        $this->lastuid = $lastuid;
        $this->messages = $messages;
    }

    /**
     * IMAPEmailChecker destructor.
     * Closes the IMAP connection if it is set.
     */
    public function __destruct()
    {
        if (isset($this->conn)) {
            imap_close($this->conn);
        }
    }

    /**
     * Validates the result of an IMAP operation.
     *
     * @param array|bool $results The results to validate.
     * @return bool True if the results are valid; false otherwise.
     */
    private function validateResults(array|bool $results): bool
    {
        if (!$results) {
            return false;
        }
        if (!is_array($results)) {
            return false;
        }
        if (count($results) === 0) {
            return false;
        }
        return true;
    }

    /**
     * Decodes the body of an email message.
     *
     * This method recursively processes MIME parts, preferring HTML over plain text.
     * It also converts the content to UTF-8 based on the charset specified in the part parameters.
     *
     * @param int $thismsg The message number (1-indexed).
     * @return string|bool The decoded message body or false if decoding fails.
     */
    private function decodeBody(int $thismsg): string|bool
    {
        $structure = imap_fetchstructure($this->conn, $thismsg);
        if (!$structure) {
            return false;
        }

        $messageParts = [];
        $hasHtml = false; // Track if HTML has been added

        /**
         * Recursive function to decode a part.
         *
         * @param object $part The current MIME part.
         * @param string $partNum The part number (as a string).
         */
        $decodePart = function ($part, $partNum) use (&$decodePart, $thismsg, &$messageParts, &$hasHtml) {
            // Skip attachments; inline images will be handled via checkForAttachments()
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                return;
            }

            $partIndex = (string)$partNum;
            $body = imap_fetchbody($this->conn, $thismsg, $partIndex);

            // Decode content based on encoding type
            switch ($part->encoding) {
                case 0:  // 7bit
                    break;
                case 1:  // 8bit
                    $body = imap_8bit($body);
                    break;
                case 2:  // Binary
                    break;
                case 3:  // Base64
                    $body = imap_base64($body);
                    break;
                case 4:  // Quoted-Printable
                    $body = quoted_printable_decode($body);
                    break;
                default:
                    break;
            }

            // Convert the decoded body to UTF-8 using the charset parameter if available.
            $charset = 'UTF-8'; // default charset
            if (isset($part->parameters) && is_array($part->parameters)) {
                foreach ($part->parameters as $param) {
                    if (strtolower($param->attribute) === 'charset') {
                        $charset = $param->value;
                        break;
                    }
                }
            }
            if (strtoupper($charset) !== 'UTF-8') {
                $body = mb_convert_encoding($body, 'UTF-8', $charset);
            }

            // If the part is multipart, process its subparts recursively
            if (isset($part->parts) && is_array($part->parts)) {
                foreach ($part->parts as $subIndex => $subPart) {
                    $decodePart($subPart, $partNum . '.' . ($subIndex + 1));
                }
            } else {
                // Prefer HTML content over plain text
                if (stripos($part->subtype, 'html') !== false) {
                    $messageParts = [$body]; // Overwrite with HTML content
                    $hasHtml = true;
                } elseif (stripos($part->subtype, 'plain') !== false) {
                    if (!$hasHtml) {
                        $messageParts[] = $body;
                    }
                }
            }
        };

        // Process all parts if available; otherwise, process the structure as a single part
        if (isset($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $decodePart($part, (string)($index + 1));
            }
        } else {
            $decodePart($structure, '1');
        }

        $message = implode("\n", $messageParts);
        return trim($message) ?: false;
    }

    /**
     * Checks for attachments and inline images in the message.
     *
     * Inline images are identified by a disposition of "inline" and a Content-ID.
     *
     * @param int $thismsg The message number (1-indexed).
     * @return array An array of attachments with keys: filename, content, type,
     *               and optionally content_id for inline images.
     */
    private function checkForAttachments(int $thismsg): array
    {
        $structure = imap_fetchstructure($this->conn, $thismsg);
        $attachments = [];

        if (isset($structure->parts) && is_array($structure->parts)) {
            for ($i = 0, $count = count($structure->parts); $i < $count; $i++) {
                $part = $structure->parts[$i];

                // Determine if the part is an attachment or an inline image
                $isAttachment = (isset($part->disposition) && strtolower($part->disposition) === 'attachment');
                $isInline    = (isset($part->disposition) && strtolower($part->disposition) === 'inline' && isset($part->id));

                if (!$isAttachment && !$isInline) {
                    continue;
                }

                $filename = 'unknown';
                if (isset($part->dparameters) && is_array($part->dparameters)) {
                    foreach ($part->dparameters as $object) {
                        if (strtolower($object->attribute) === 'filename') {
                            $filename = $object->value;
                            break;
                        }
                    }
                }

                $content = imap_fetchbody($this->conn, $thismsg, (string)($i + 1));
                // Decode content based on encoding type
                switch ($part->encoding) {
                    case 3:  // Base64
                        $content = imap_base64($content);
                        break;
                    case 4:  // Quoted-printable
                        $content = quoted_printable_decode($content);
                        break;
                }

                $attachment = [
                    'filename' => $filename,
                    'content'  => $content,
                    'type'     => $part->subtype, // e.g. JPEG, PNG, etc.
                ];

                // If inline image, store the content_id (without angle brackets)
                if ($isInline && isset($part->id)) {
                    $attachment['content_id'] = trim($part->id, '<>');
                }

                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /**
     * Retrieves recipient email addresses for a given type.
     *
     * @param string $type The recipient type: "to", "cc", or "bcc".
     * @param int $thismsg The message number.
     * @param \stdClass $rfc_header The parsed RFC822 headers.
     * @return array|bool An array of email addresses, or false on failure.
     */
    private function getRecipientAddresses(string $type, int $thismsg, \stdClass $rfc_header): array|bool
    {
        if ($type !== "to" && $type !== "cc" && $type !== "bcc") {
            return false;
        }
        if (!$thismsg || empty($thismsg) || !$rfc_header || empty($rfc_header)) {
            return false;
        }

        $toaddresses = [];
        if ($type === "to" && isset($rfc_header->to)) {
            foreach ($rfc_header->to as $thisto) {
                $toaddresses[] = $thisto->mailbox . "@" . $thisto->host;
            }
        }
        if ($type === "cc" && isset($rfc_header->cc)) {
            foreach ($rfc_header->cc as $thisto) {
                $toaddresses[] = $thisto->mailbox . "@" . $thisto->host;
            }
        }
        if ($type === "bcc" && isset($rfc_header->bcc)) {
            foreach ($rfc_header->bcc as $thisto) {
                $toaddresses[] = $thisto->mailbox . "@" . $thisto->host;
            }
        }
        return $toaddresses;
    }

    /**
     * Replaces CID references in HTML with corresponding inline image data URIs.
     *
     * Searches for "cid:" references in the HTML and replaces them with base64-encoded
     * data URIs using the matching inline image attachment.
     *
     * @param string $html The original HTML content.
     * @param array $attachments The list of attachments from checkForAttachments().
     * @return string The HTML content with embedded inline images.
     */
    private function embedInlineImages(string $html, array $attachments): string
    {
        return preg_replace_callback('/cid:([^"\'>]+)/i', function ($matches) use ($attachments) {
            $cid = $matches[1];
            foreach ($attachments as $attachment) {
                if (isset($attachment['content_id']) && $attachment['content_id'] === $cid) {
                    // Determine the MIME type from the attachment subtype.
                    $mime = "image/" . strtolower($attachment['type']);
                    $base64 = base64_encode($attachment['content']);
                    return "data:$mime;base64,$base64";
                }
            }
            // Return the original match if no corresponding attachment is found.
            return $matches[0];
        }, $html);
    }

    /**
     * Processes an individual email message and returns its data as an associative array.
     *
     * @param int $msgId The message UID to process.
     * @return array|null The processed message data, or null if decoding fails.
     */
    private function processMessage(int $msgId): ?array
    {
        // Convert UID to sequence number if needed.
        $msgno = imap_msgno($this->conn, $msgId);
        if ($msgno === 0) {
            // If conversion fails, assume $msgId is already a valid sequence number.
            $msgno = $msgId;
        }

        $header = imap_headerinfo($this->conn, $msgno);
        $rfc_header = imap_rfc822_parse_headers(imap_fetchheader($this->conn, $msgno));
        $message = $this->decodeBody($msgno);
        if (!$message) {
            return null;
        }
        $attachments = $this->checkForAttachments($msgno);
        $message = $this->embedInlineImages($message, $attachments);

        $processed = [];
        $processed['message_id'] = htmlentities($header->message_id);
        $processed['subject'] = mb_decode_mimeheader($header->Subject);
        $processed['message_body'] = $message;

        if (isset($rfc_header->to)) {
            $processed['tocount'] = count($rfc_header->to);
            $processed['to'] = $this->getRecipientAddresses("to", $msgno, $rfc_header);
        }
        if (isset($rfc_header->cc) && !empty($rfc_header->cc)) {
            $processed['cccount'] = count($rfc_header->cc);
            $processed['cc'] = $this->getRecipientAddresses("cc", $msgno, $rfc_header);
        }
        if (isset($rfc_header->bcc) && !empty($rfc_header->bcc)) {
            $processed['bcccount'] = count($rfc_header->bcc);
            $processed['bcc'] = $this->getRecipientAddresses("bcc", $msgno, $rfc_header);
        }

        // Overwrite cc and bcc with header values if available.
        if (isset($header->cc) && is_array($header->cc)) {
            $ccs = [];
            foreach ($header->cc as $cc) {
                $ccs[] = $cc->mailbox . "@" . $cc->host;
            }
            $processed['cc'] = $ccs;
        } else {
            $processed['cc'] = [];
        }
        
        if (isset($header->bcc) && is_array($header->bcc)) {
            $bccs = [];
            foreach ($header->bcc as $bcc) {
                $bccs[] = $bcc->mailbox . "@" . $bcc->host;
            }
            $processed['bcc'] = $bccs;
        } else {
            $processed['bcc'] = [];
        }
        
        $processed['fromaddress'] = $header->sender[0]->mailbox . "@" . $header->sender[0]->host;

        $senderName = '';
        if (isset($header->sender[0]) && isset($header->sender[0]->personal)) {
            $senderName = mb_decode_mimeheader($header->sender[0]->personal);
        }
        $processed['from'] = $senderName ?: $header->fromaddress;

        $processed['message_number'] = $header->Msgno;
        $processed['date'] = $header->date;
        $thisbid = "n/a";
        if (property_exists($header, "Subject") && preg_match("/#(\d+)/", $header->Subject, $matches)) {
            $thisbid = $matches[0];
        }
        $processed['bid'] = str_replace("#", "", $thisbid);
        $processed['unseen'] = $header->Unseen;
        $processed['attachments'] = $attachments;

        // Add the UID to the processed array using the sequence number.
        $processed['uid'] = imap_uid($this->conn, $msgno);

        return $processed;
    }

    /**
     * Retrieves all emails from the mailbox.
     *
     * For each email, it decodes the body, retrieves attachments, embeds inline images,
     * and extracts header details.
     *
     * @return array An array of emails.
     */
    public function checkAllEmail(): array
    {
        $msg_count = imap_num_msg($this->conn);
        imap_headers($this->conn);

        for ($i = 1; $i <= $msg_count; $i++) {
            $processed = $this->processMessage($i);
            if ($processed !== null) {
                $this->messages[$i] = $processed;
            }
        }

        return $this->messages;
    }

    /**
     * Retrieves emails from the mailbox since a specified date.
     *
     * The date must be provided as a DateTime object, and the method returns emails from that day onward.
     *
     * @param DateTime $date The starting date.
     * @return bool|array False on failure or an array of emails.
     */
    public function checkSinceDate(DateTime $date): bool|array
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
        foreach ($search as $msgId) {
            $processed = $this->processMessage($msgId);
            if ($processed !== null) {
                $this->messages[$msgId] = $processed;
            }
        }

        return $this->messages;
    }

    /**
     * Retrieves emails from the mailbox since the last specified UID.
     *
     * It processes emails starting from the given UID until the most recent message.
     *
     * @param int $uid The UID from which to start retrieving emails.
     * @return bool|array False on failure or an array of emails.
     */
    public function checkSinceLastUID(int $uid): bool|array
    {
        if (!isset($uid)) {
            return false;
        }

        $search = imap_fetch_overview($this->conn, $uid . ":*", FT_UID);
        if (!$this->validateResults($search)) {
            return false;
        }

        imap_headers($this->conn);
        foreach ($search as $overview) {
            $msgId = $overview->uid;
            $processed = $this->processMessage($msgId);
            if ($processed !== null) {
                $this->messages[$msgId] = $processed;
            }
        }

        // Update lastuid with the last processed message UID.
        $this->lastuid = $msgId;

        return $this->messages;
    }

    /**
     * Deletes an email from the mailbox.
     *
     * This method marks the specified email for deletion and then expunges the mailbox.
     *
     * @param int $msgIdentifier The UID of the email to delete.
     * @return bool True on success, false on failure.
     */
    public function deleteEmail(int $msgIdentifier): bool
    {
        if (!imap_delete($this->conn, (string)$msgIdentifier, FT_UID)) {
            return false;
        }

        // Permanently remove emails marked for deletion.
        return imap_expunge($this->conn);
    }

    /**
     * Archives an email by moving it to a specified folder.
     *
     * This method moves the email from the current mailbox to the target archive folder
     * and then expunges the mailbox to remove the email from its original location.
     *
     * @param int    $msgIdentifier The UID of the email to archive.
     * @param string $archiveFolder The target folder where the email should be moved (default is "Archive").
     * @return bool True on success, false on failure.
     */
    public function archiveEmail(int $msgIdentifier, string $archiveFolder = 'Archive'): bool
    {
        // Move the email to the archive folder using CP_UID flag.
        if (!imap_mail_move($this->conn, (string)$msgIdentifier, $archiveFolder, CP_UID)) {
            return false;
        }

        // Remove the moved email from the current mailbox.
        return imap_expunge($this->conn);
    }
}