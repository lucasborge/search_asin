<?php

function send_mail ($title, $body, $attach, $mailbox) {

    $email = new PHPMailer ();
    $email->isSMTP ();
    $email->SMTPDebug = 0;
    $email->Debugoutput = 'html';
    $email->Host = EMAIL_HOST;
    $email->Port = 25;
    //$email->SMTPSecure = 'ssl';
    $email->SMTPAuth = true;
    $email->Username = EMAIL_USER;
    $email->Password = EMAIL_PASS;

    $email->setFrom(EMAIL_USER, '');
    $email->Subject = $title;
    $email->msgHTML = $body;
    $email->Body = $body;

    if ($mailbox != "" && $mailbox != null) {
        foreach ($mailbox as $mailadd) {
            $email->AddAddress ($mailadd, '');
        }
    }

    if (strlen ($attach) > 0) {
        $attach_file = "attach.txt";

        $email->AddAttachment ($attach, $attach_file);
    }

    if (!$email->send ()) {
        echo "Email was not sent!" . PHP_EOL;
        echo "Mailer error : " . $email->ErrorInfo . PHP_EOL;
        return false;
    } else {
        echo "Message has been sent!" . PHP_EOL;
    }

    return true;
}

?>