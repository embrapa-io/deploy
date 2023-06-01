<?php

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

class Mail
{
    private $mailer = null;

    private $log = null;

    public function __construct ()
	{
        // https://symfony.com/doc/current/mailer.html
        // https://code.tutsplus.com/tutorials/send-emails-in-php-using-the-swift-mailer--cms-31218

        $transport = Transport::fromDsn ('smtp://'. getenv ('SMTP_HOST') .':'. getenv ('SMTP_PORT') .'?verify_peer=0');

        $this->mailer = new Mailer ($transport);

        $this->log = getenv ('LOG_MAIL');
    }

    public function send ($subject, $message, $cc = [])
    {
        $email = (new Email ())
            ->from ('Releaser <no-reply@embrapa.io>')
            ->to ($this->log)
            ->subject ($subject)
            ->text ($message);

        if (is_array ($cc) && sizeof ($cc)) $email->cc (...$cc);

        $this->mailer->send ($email);
    }
}
