<?php
/**
 *	CoinDebtCollection.com Arb Award Submittor.
 *
 *      Polls a Gmail account for new messages and checks them for arb award attachments.
 *      Upon detection of an arb award attachment, the PDF is parsed with the required details scraped.
 *      A cURL POST request then submits the claim to CoinDebtCollection.com
 *	Uses PHP IMAP extension, so make sure it is enabled in your php.ini,
 *	extension=php_imap.dll
 *
 */

set_time_limit(3000);

include 'vendor/autoload.php';

function get_phrase_after_string($haystack,$needle)
{
    //length of needle
    $len = strlen($needle);

    //matches $needle until hits a \n or \r
    if (preg_match("#$needle([^\r\n]+)#i", $haystack, $match)) {
        //length of matched text
        $rsp = strlen($match[0]);

        //determine what to remove
        $back = $rsp - $len;

        return trim(substr($match[0],- $back));
    }
}

function submit_debt($url, $array)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,$url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,
            http_build_query($array));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $server_output = curl_exec ($ch);

    curl_close ($ch);

    return $server_output;
}

/* connect to gmail with your credentials */
$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = 'cdc.parser@gmail.com'; # e.g somebody@gmail.com
$password = 'coindebtcollection';

/* try to connect */
$inbox = imap_open($hostname,$username,$password) or die('Cannot connect to Gmail: ' . imap_last_error());

/* get all new emails. If set to 'ALL' instead
 * of 'NEW' retrieves all the emails, but can be
 * resource intensive, so the following variable,
 * $max_emails, puts the limit on the number of emails downloaded.
 *
 */
$emails = imap_search($inbox,'ALL');

/* useful only if the above search is set to 'ALL' */
$max_emails = 16;

/* if any emails found, iterate through each email */
if ($emails) {

$count = 1;

/* put the newest emails on top */
rsort($emails);

/* for every email... */
foreach ($emails as $email_number) {

    /* get information specific to this email */
    $overview = imap_fetch_overview($inbox,$email_number,0);

    /* get mail message */
    $message = imap_fetchbody($inbox,$email_number,2);

    /* get mail structure */
    $structure = imap_fetchstructure($inbox, $email_number);

    $attachments = array();

    /* if any attachments found... */
    if (isset($structure->parts) && count($structure->parts)) {
        for ($i = 0; $i < count($structure->parts); $i++) {
            $attachments[$i] = array(
                'is_attachment' => false,
                'filename' => '',
                'name' => '',
                'attachment' => ''
            );

            if ($structure->parts[$i]->ifdparameters) {
                foreach ($structure->parts[$i]->dparameters as $object) {
                    if (strtolower($object->attribute) == 'filename') {
                        $attachments[$i]['is_attachment'] = true;
                        $attachments[$i]['filename'] = $object->value;
                    }
                }
            }

            if ($structure->parts[$i]->ifparameters) {
                foreach ($structure->parts[$i]->parameters as $object) {
                    if (strtolower($object->attribute) == 'name') {
                        $attachments[$i]['is_attachment'] = true;
                        $attachments[$i]['name'] = $object->value;
                    }
                }
            }

            if ($attachments[$i]['is_attachment']) {
                $attachments[$i]['attachment'] = imap_fetchbody($inbox, $email_number, $i+1);

                /* 4 = QUOTED-PRINTABLE encoding */
                if ($structure->parts[$i]->encoding == 3) {
                    $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                }
                /* 3 = BASE64 encoding */
                elseif ($structure->parts[$i]->encoding == 4) {
                    $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                }
            }
        }
    }

    /* iterate through each attachment and save it */
    foreach ($attachments as $attachment) {
        if ($attachment['is_attachment'] == 1) {
            $filename = $attachment['name'];
            if(empty($filename)) $filename = $attachment['filename'];

            $filename = time() . ".dat";

            /*
 			* Save the attachment to disk.
 			*/
            $fp = fopen($filename, "w+");
            fwrite($fp, $attachment['attachment']);
            fclose($fp);

            /*
 			* Parse the attachment.
 			*/
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filename);

            $text = $pdf->getText();
            $text = preg_replace('/\s+/', ' ', $text);

            $header = imap_headerinfo($inbox, $email_number);
            $creditor_email = $header->from[0]->mailbox . "@" . $header->from[0]->host;

            $creditor_name = $header->from[0]->personal;

            $loan_id = explode(")",get_phrase_after_string($text, "BTCJam Loan No."))['0'];
            $loan_amount = explode(" ",get_phrase_after_string($text, "bitcoin loan in the amount of"))['0'];
            $loan_outstanding = explode(" ",get_phrase_after_string($text, "stopped making payments is"))['0'];

            /*
			* Compile our array for submission
			*/
            $array = array(
                "creditor_email" => $creditor_email,
                "creditor_name" => $creditor_name,
                "loan_url" => $loan_url,
                "loan_amount" => $loan_amount,
                "loan_outstanding" => $loan_outstanding
            );

        $url = "HTTP://WHERE SHOULD WE SEND TO?"

            submit_debt($url, $array);

        }

    }

    imap_delete($inbox, $email_number);

    if($count++ >= $max_emails) break;
    }

}

/* close the connection */
imap_close($inbox);
